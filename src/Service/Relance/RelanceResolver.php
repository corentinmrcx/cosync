<?php declare(strict_types=1);

namespace App\Service\Relance;

use App\DTO\DernierContact;
use App\DTO\RelanceDue;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\EtapeRelance;
use App\Enum\TypeMail;
use App\Repository\EnvoiMailRepository;
use App\Repository\LicencieRepository;
use App\Service\Referentiel\ClubSettingsService;

/**
 * Qui reste-t-il à relancer, et pour quoi ?
 *
 * Une licence non soldée finit par se relancer à la main, une par une, quand quelqu'un y
 * pense. Ce resolver énonce la règle une fois, et les trois façons de relancer — le cron
 * quotidien, l'écran groupé, le bouton d'une fiche — la lisent au même endroit. Les faire
 * diverger reviendrait à ce que le robot écrive à quelqu'un que l'écran n'affichait pas.
 *
 * **L'ancre est le dernier mail reçu, pas la date d'inscription.** C'est la règle qui tient
 * tout le dispositif : une relance passée à la main hier repousse mécaniquement celle du
 * robot de dix jours. Sans elle, les deux partiraient à quelques heures d'écart et le club
 * harcèlerait ceux qu'il vient déjà de relancer.
 *
 * Les cinq autres conditions :
 *
 * - **La cotisation n'est pas soldée** — `estSoldee()`, jamais `=== VALIDATED` : c'est
 *   l'encaissement qui intéresse le licencié, la signature FootClubs est interne au club.
 * - **Le lien lui a déjà été envoyé.** On ne relance pas quelqu'un qu'on n'a jamais
 *   contacté : ça, c'est l'envoi initial, et c'est une décision d'admin sur son écran.
 * - **Une adresse existe.** Sans email, la relance se fait au téléphone.
 * - **Le plafond n'est pas atteint.** Passé trois relances, insister par mail ne sert plus.
 * - **L'interrupteur est allumé** — vérifié par {@see RelanceService} et non ici : l'écran
 *   groupé doit pouvoir montrer la liste robot éteint, c'est même sa raison d'être avant
 *   qu'on l'allume.
 *
 * Les lectures sont **groupées** : la liste des joueurs affiche le compteur des relances en
 * attente à chaque ouverture, et deux requêtes par licencié en feraient trois cents.
 */
final class RelanceResolver
{
    public function __construct(
        private readonly LicencieRepository $licencieRepo,
        private readonly EnvoiMailRepository $envoiMailRepo,
        private readonly ClubSettingsService $clubSettings,
    ) {}

    /**
     * Les licenciés à relancer, du plus anciennement contacté au plus récent — l'ordre dans
     * lequel on veut les traiter.
     *
     * @return RelanceDue[]
     */
    public function dues(Season $season, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): array
    {
        $licencies = $this->licencieRepo->findBySeason($season);

        if ($licencies === []) {
            return [];
        }

        $derniersContacts = $this->envoiMailRepo->dernierEnvoiParLicencie($licencies);
        $relancesEnvoyees = $this->envoiMailRepo->compterEnvoisParLicencie($licencies, TypeMail::relances());

        $settings = $this->clubSettings->get();
        $delai = $settings->getRelanceDelaiJours();
        $plafond = $settings->getRelanceMax();

        $dues = [];

        foreach ($licencies as $licencie) {
            $uuid = (string) $licencie->getUuid();

            $due = $this->evaluer(
                $licencie,
                $derniersContacts[$uuid] ?? null,
                $relancesEnvoyees[$uuid] ?? 0,
                $delai,
                $plafond,
                $maintenant,
            );

            if ($due !== null) {
                $dues[] = $due;
            }
        }

        usort(
            $dues,
            static fn (RelanceDue $a, RelanceDue $b): int => $a->dernierContact->date <=> $b->dernierContact->date,
        );

        return $dues;
    }

    /** Le même verdict pour une seule personne — celui qu'affiche sa fiche. */
    public function pour(Licencie $licencie, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): ?RelanceDue
    {
        $settings = $this->clubSettings->get();

        return $this->evaluer(
            $licencie,
            $this->envoiMailRepo->dernierEnvoi($licencie),
            $this->envoiMailRepo->compterEnvois($licencie, TypeMail::relances()),
            $settings->getRelanceDelaiJours(),
            $settings->getRelanceMax(),
            $maintenant,
        );
    }

    /**
     * Ce qui manque à cette licence, indépendamment de tout délai.
     *
     * Lu aussi par la relance manuelle depuis une fiche, qui ne passe par aucune des
     * conditions ci-dessus : l'admin décide, mais le mail envoyé doit rester le bon.
     * Null quand la licence est soldée — il n'y a plus rien à relancer.
     */
    public function etapePour(Licencie $licencie): ?EtapeRelance
    {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null || $dossier->estSoldee()) {
            return null;
        }

        return $dossier->getFormCompletedAt() === null ? EtapeRelance::DOSSIER : EtapeRelance::PAIEMENT;
    }

    /** Null si cette personne n'a rien à recevoir — le détail des motifs est dans le docblock de classe. */
    private function evaluer(
        Licencie $licencie,
        ?\DateTimeImmutable $dernierEnvoi,
        int $dejaRelance,
        int $delai,
        int $plafond,
        \DateTimeImmutable $maintenant,
    ): ?RelanceDue {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null || $dossier->estSoldee()) {
            return null;
        }

        if ($licencie->getLinkSentAt() === null || $licencie->getEmail() === null) {
            return null;
        }

        if ($dejaRelance >= $plafond) {
            return null;
        }

        // Jamais null en pratique — `linkSentAt` implique une ligne au journal, que la
        // migration a reprise. Le garde évite qu'une donnée bancale relance en boucle.
        if ($dernierEnvoi === null) {
            return null;
        }

        $dernierContact = new DernierContact($dernierEnvoi, $maintenant);

        if ($dernierContact->joursEcoules < $delai) {
            return null;
        }

        $etape = $this->etapePour($licencie);

        if ($etape === null) {
            return null;
        }

        return new RelanceDue($licencie, $etape, $dernierContact, $dejaRelance + 1);
    }
}
