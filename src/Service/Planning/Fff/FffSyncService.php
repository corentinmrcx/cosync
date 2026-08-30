<?php declare(strict_types=1);

namespace App\Service\Planning\Fff;

use App\DTO\Planning\MatchImporteData;
use App\DTO\Planning\PlanningSyncResultat;
use App\Entity\Season;
use App\Exception\FffApiException;
use App\Repository\MatchDomicileRepository;
use App\Service\Planning\PlanningMatchService;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aligne le planning sur le calendrier du district. Idempotent : deux passages de suite
 * ne changent rien la seconde fois.
 *
 * La réconciliation se fait sur `ma_no`, l'identifiant fédéral du match — jamais sur la
 * date. Une clé fondée sur la date recréerait un doublon à chaque report de rencontre,
 * c'est-à-dire précisément dans le cas où la synchronisation sert à quelque chose.
 *
 * Trois décisions, dans cet ordre :
 *
 * 1. **`ma_no` inconnu** → création ;
 * 2. **`ma_no` connu et la ligne suit toujours la FFF** → mise à jour de la date, de
 *    l'heure, de la catégorie et de l'adversaire. La note et le masque, qui appartiennent
 *    au club, ne sont pas touchés ;
 * 3. **`ma_no` connu mais la ligne a été détachée** → on n'y touche pas. C'est tout le
 *    sens du détachement, et c'est aussi ce qui empêche la recréation en double.
 *
 * Un match disparu du flux est supprimé **s'il est resté intact**. Annoté ou masqué, il
 * est conservé et signalé : le club y a mis du travail, l'automate n'a pas à l'effacer
 * en silence.
 */
final class FffSyncService
{
    public function __construct(
        private readonly FffApiClient $api,
        private readonly FffMatchMapper $mapper,
        private readonly PlanningMatchService $matchService,
        private readonly MatchDomicileRepository $matchRepo,
        private readonly ClubSettingsService $clubSettings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function estConfigure(): bool
    {
        return $this->clubSettings->get()->estRattacheALaFff();
    }

    public function estAutomatique(): bool
    {
        return $this->clubSettings->get()->isFffSyncActive();
    }

    /**
     * Synchronise la saison.
     *
     * L'échec est **rendu**, pas levé : l'API fédérale refuse les appels de certains
     * hébergements, et cette page doit rester utilisable pour saisir à la main.
     *
     * @param bool $simulation true : on calcule et on rapporte, on n'écrit rien
     */
    public function synchroniser(Season $season, bool $simulation = false): PlanningSyncResultat
    {
        $clubNo = $this->clubSettings->get()->getFffClubNo();

        if ($clubNo === null) {
            return PlanningSyncResultat::echec('Aucun numéro de club FFF renseigné : renseignez-le dans les réglages du planning.');
        }

        try {
            $lignes = $this->api->matchs($clubNo);
        } catch (FffApiException $e) {
            $this->logger->warning('Synchronisation FFF en échec', ['exception' => $e, 'club_no' => $clubNo]);

            return PlanningSyncResultat::echec($e->messageAdmin());
        }

        return $this->appliquer($this->mapper->domicile($lignes, $clubNo), $season, $simulation);
    }

    /**
     * Vérifie un numéro de club et rend sa fiche, pour que l'admin confirme qu'il a saisi
     * le bon avant d'enregistrer. Un numéro faux ramènerait le calendrier d'un autre club
     * sans que rien ne le signale.
     *
     * @return array{nom: string, district: string, ville: string, affiliation: ?int}
     *
     * @throws FffApiException
     */
    public function verifierClub(int $clubNo): array
    {
        $club = $this->api->club($clubNo);
        $district = is_array($club['district'] ?? null) ? $club['district'] : [];

        return [
            'nom' => is_string($club['name'] ?? null) ? $club['name'] : 'Club sans nom',
            'district' => is_string($district['name'] ?? null) ? $district['name'] : '',
            'ville' => is_string($club['location'] ?? null) ? $club['location'] : '',
            'affiliation' => is_int($club['affiliation_number'] ?? null) ? $club['affiliation_number'] : null,
        ];
    }

    /**
     * @param list<MatchImporteData> $entrants
     */
    private function appliquer(array $entrants, Season $season, bool $simulation): PlanningSyncResultat
    {
        $existants = $this->matchRepo->findParMaNo($season);

        $crees = [];
        $misAJour = [];
        $inchanges = 0;
        $maNosVus = [];

        foreach ($entrants as $entrant) {
            $maNo = $entrant->fffMaNo;

            if ($maNo === null) {
                continue;
            }

            $maNosVus[$maNo] = true;
            $existant = $existants[$maNo] ?? null;

            if ($existant === null) {
                $crees[] = $entrant->resume();

                if (!$simulation) {
                    $this->matchService->creerDepuisFff($entrant, $season);
                }

                continue;
            }

            // Ligne détachée : le club a repris la main dessus, la FFF ne l'écrase plus.
            if (!$existant->suitLaFff()) {
                ++$inchanges;

                continue;
            }

            if ($simulation) {
                // En simulation on compare sans écrire : appliquer puis annuler laisserait
                // l'entité modifiée en mémoire, et un flush ultérieur la persisterait.
                $aChange = $this->differe($existant, $entrant);
            } else {
                $aChange = $this->matchService->appliquerDepuisFff($existant, $entrant);
            }

            if ($aChange) {
                $misAJour[] = $entrant->resume();
            } else {
                ++$inchanges;
            }
        }

        [$supprimes, $aVerifier] = $this->traiterLesDisparus($existants, $maNosVus, $simulation);

        if (!$simulation) {
            $this->em->flush();
        }

        return PlanningSyncResultat::succes($crees, $misAJour, $supprimes, $aVerifier, $inchanges);
    }

    /**
     * @param array<int, \App\Entity\MatchDomicile> $existants
     * @param array<int, true>                      $maNosVus
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function traiterLesDisparus(array $existants, array $maNosVus, bool $simulation): array
    {
        $supprimes = [];
        $aVerifier = [];

        foreach ($existants as $maNo => $match) {
            if (isset($maNosVus[$maNo]) || !$match->suitLaFff()) {
                continue;
            }

            $resume = sprintf(
                '%s — %s%s',
                $match->getDate()->format('d/m/Y'),
                $match->getCategorie(),
                $match->getAdversaire() !== null ? ' contre ' . $match->getAdversaire() : '',
            );

            if ($match->estIntacte()) {
                $supprimes[] = $resume;

                if (!$simulation) {
                    $this->em->remove($match);
                }

                continue;
            }

            $aVerifier[] = $resume;
        }

        return [$supprimes, $aVerifier];
    }

    private function differe(\App\Entity\MatchDomicile $match, MatchImporteData $entrant): bool
    {
        return $match->getDate()->format('Y-m-d') !== $entrant->date->format('Y-m-d')
            || $match->getHeure() !== $entrant->heure
            || $match->getCategorie() !== $entrant->categorie
            || $match->getAdversaire() !== $entrant->adversaire
            || $match->getFffCompetition() !== $entrant->fffCompetition
            || $match->getFffTerrain() !== $entrant->fffTerrain;
    }
}
