<?php declare(strict_types=1);

namespace App\Service\Occupation;

use App\DTO\Occupation\CreneauOccupationData;
use App\DTO\Occupation\RepriseGrilleResultat;
use App\Entity\CreneauOccupation;
use App\Entity\Season;
use App\Entity\Team;
use App\Repository\CreneauOccupationRepository;
use App\Repository\EspaceTerrainRepository;
use App\Repository\SeasonRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les gestes du club sur sa grille d'occupation : poser un créneau, le corriger, le retirer,
 * et reprendre la grille de l'an dernier.
 *
 * Ce qui n'est **pas** ici, et n'a pas à y venir : la détection de chevauchement. Deux
 * créneaux au même horaire sont un cas courant (deux catégories sur les deux moitiés du
 * terrain d'honneur, ou deux terrains pris en même temps), et les refuser bloquerait un
 * usage réel du complexe.
 */
final class CreneauOccupationService
{
    /** Le pas de la grille : c'est aussi celui du glissé à la souris. */
    private const PAS_MINUTES = 15;

    public function __construct(
        private readonly CreneauOccupationRepository $creneauRepo,
        private readonly EspaceTerrainRepository $espaceRepo,
        private readonly TeamRepository $teamRepo,
        private readonly SeasonRepository $seasonRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return list<CreneauOccupation> */
    public function listerPourAdmin(Season $season): array
    {
        return $this->creneauRepo->findParSaison($season);
    }

    public function creer(CreneauOccupationData $data, Season $season): CreneauOccupation
    {
        $creneau = (new CreneauOccupation())->setSeason($season);

        $this->appliquer($creneau, $data, $season);

        $this->em->persist($creneau);
        $this->em->flush();

        return $creneau;
    }

    public function modifier(CreneauOccupation $creneau, CreneauOccupationData $data): void
    {
        $this->appliquer($creneau, $data, $creneau->getSeason());
        $this->em->flush();
    }

    public function supprimer(CreneauOccupation $creneau): void
    {
        $this->em->remove($creneau);
        $this->em->flush();
    }

    /**
     * Recopie la grille de la saison précédente dans celle-ci.
     *
     * C'est le geste annuel qui fait vivre l'outil : sans lui, tout serait à ressaisir
     * chaque 1ᵉʳ juillet, et une grille qu'on ne réécrit pas est une grille qui ment.
     *
     * Les équipes appartenant à une saison, elles se retrouvent **par leur nom** — « U15 »
     * de 2026-2027 pour « U15 » de 2025-2026. Une équipe sans équivalent laisse le créneau
     * sans équipe plutôt que d'en inventer une : l'admin voit ce qui reste à compléter,
     * là où un rattachement approximatif ferait tondre la mairie pour la mauvaise catégorie.
     * Les terrains, eux, sont hors saison : ils se reprennent tels quels.
     *
     * @throws \DomainException si la grille de la saison n'est pas vide, ou s'il n'y a pas
     *                          de saison antérieure — recopier par-dessus une grille déjà
     *                          composée la doublerait en silence
     */
    public function reprendreLaSaisonPrecedente(Season $season): RepriseGrilleResultat
    {
        if ($this->creneauRepo->compterParSaison($season) > 0) {
            throw new \DomainException('La grille de cette saison contient déjà des créneaux : videz-la avant de reprendre celle de l\'an dernier, sinon les créneaux se retrouveraient en double.');
        }

        $precedente = $this->seasonRepo->findPrecedente($season);

        if ($precedente === null) {
            throw new \DomainException('Aucune saison antérieure : il n\'y a pas de grille à reprendre.');
        }

        $sources = $this->creneauRepo->findParSaison($precedente);

        if ($sources === []) {
            throw new \DomainException(sprintf('La saison %s n\'a pas de grille d\'occupation.', $precedente->getLabel()));
        }

        $equipes = $this->equipesParNom($season);
        $reprises = 0;
        $sansEquipe = 0;

        foreach ($sources as $source) {
            $nom = $source->getEquipe()?->getName();
            $equipe = $nom === null ? null : ($equipes[$nom] ?? null);

            if ($equipe === null) {
                ++$sansEquipe;
            }

            $this->em->persist(
                (new CreneauOccupation())
                    ->setSeason($season)
                    ->setJour($source->getJour())
                    ->setHeureDebut($source->getHeureDebut())
                    ->setHeureFin($source->getHeureFin())
                    ->setEquipe($equipe)
                    ->setEspace($source->getEspace())
                    ->setUsage($source->getUsage()),
            );

            ++$reprises;
        }

        $this->em->flush();

        return new RepriseGrilleResultat($precedente->getLabel(), $reprises, $sansEquipe);
    }

    /**
     * @throws \DomainException sur une saisie que la grille ne saurait pas représenter
     */
    private function appliquer(CreneauOccupation $creneau, CreneauOccupationData $data, Season $season): void
    {
        if ($data->jour === null) {
            throw new \DomainException('Le jour de la semaine est obligatoire.');
        }

        if ($data->usage === null) {
            throw new \DomainException('L\'usage du créneau est obligatoire.');
        }

        $debut = $this->horaire($data->heureDebut, 'de début');
        $fin = $this->horaire($data->heureFin, 'de fin');

        if (CreneauOccupation::enMinutes($fin) <= CreneauOccupation::enMinutes($debut)) {
            throw new \DomainException('L\'heure de fin doit être postérieure à l\'heure de début.');
        }

        $espace = $data->espaceId === null ? null : $this->espaceRepo->find($data->espaceId);

        if ($espace === null) {
            throw new \DomainException('Le terrain est obligatoire.');
        }

        $equipe = $data->equipeId === null ? null : $this->teamRepo->find($data->equipeId);

        if ($equipe === null) {
            throw new \DomainException('L\'équipe est obligatoire.');
        }

        // Une équipe appartient à une saison : celle d'une autre saison désignerait, sur le
        // document, une catégorie qui ne joue plus.
        if ($equipe->getSeason()->getId() !== $season->getId()) {
            throw new \DomainException('Cette équipe n\'appartient pas à la saison de travail.');
        }

        $creneau
            ->setJour($data->jour)
            ->setHeureDebut($debut)
            ->setHeureFin($fin)
            ->setEquipe($equipe)
            ->setEspace($espace)
            ->setUsage($data->usage);
    }

    /**
     * `HH:MM` accroché au quart d'heure.
     *
     * L'arrondi plutôt que le refus : la grille ne sait pas dessiner plus fin que le quart
     * d'heure, et rejeter un « 18:07 » — qu'on ne tape jamais volontairement — obligerait à
     * ressaisir tout le créneau pour une minute que personne ne lira sur le document.
     */
    private function horaire(string $brut, string $lequel): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($brut), $morceaux) !== 1) {
            throw new \DomainException(sprintf('L\'heure %s est obligatoire, au format 18:30.', $lequel));
        }

        $minutes = (int) $morceaux[1] * 60 + (int) $morceaux[2];

        if ($minutes > 24 * 60) {
            throw new \DomainException(sprintf('L\'heure %s sort de la journée.', $lequel));
        }

        $minutes = (int) (round($minutes / self::PAS_MINUTES) * self::PAS_MINUTES);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** @return array<string, Team> */
    private function equipesParNom(Season $season): array
    {
        $parNom = [];

        foreach ($this->teamRepo->findBySeason($season) as $equipe) {
            $parNom[$equipe->getName()] = $equipe;
        }

        return $parNom;
    }
}
