<?php declare(strict_types=1);

namespace App\Service\Occupation;

use App\Entity\EspaceTerrain;
use App\Repository\CreneauOccupationRepository;
use App\Repository\EspaceTerrainRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le référentiel des terrains du complexe.
 *
 * Hors saison, donc partagé par toutes les grilles : un terrain renommé se renomme partout,
 * y compris sur les créneaux des saisons passées. C'est voulu — le document dit où l'on
 * joue, pas comment on appelait l'endroit il y a deux ans.
 */
final class EspaceTerrainService
{
    public function __construct(
        private readonly EspaceTerrainRepository $espaceRepo,
        private readonly CreneauOccupationRepository $creneauRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Le référentiel et, pour chaque terrain, le nombre de créneaux qui le désignent.
     *
     * @return array{terrains: EspaceTerrain[], reservations: array<int, int>}
     */
    public function referentiel(): array
    {
        return [
            'terrains' => $this->espaceRepo->findOrdonnes(),
            'reservations' => $this->creneauRepo->compterParTerrain(),
        ];
    }

    /** @throws \DomainException sur un nom vide ou déjà pris */
    public function creer(string $nom): EspaceTerrain
    {
        $nom = $this->nom($nom);

        $espace = (new EspaceTerrain())
            ->setNom($nom)
            ->setOrdre($this->espaceRepo->prochainOrdre());

        $this->em->persist($espace);
        $this->em->flush();

        return $espace;
    }

    /** @throws \DomainException sur un nom vide ou déjà pris */
    public function renommer(EspaceTerrain $espace, string $nom): void
    {
        $espace->setNom($this->nom($nom, $espace));
        $this->em->flush();
    }

    public function basculerActif(EspaceTerrain $espace): void
    {
        $espace->setActif(!$espace->isActif());
        $this->em->flush();
    }

    /**
     * @throws \DomainException si un créneau le désigne encore — le supprimer emporterait
     *                          des lignes de grille sans le dire. Le retrait du service est
     *                          la sortie prévue pour un terrain qui ne sert plus.
     */
    public function supprimer(EspaceTerrain $espace): void
    {
        $creneaux = $this->creneauRepo->compterParEspace($espace);

        if ($creneaux > 0) {
            throw new \DomainException(sprintf('Ce terrain est réservé sur %d créneau%s, toutes saisons confondues : retirez-le du service plutôt que de le supprimer.', $creneaux, $creneaux > 1 ? 'x' : ''));
        }

        $this->em->remove($espace);
        $this->em->flush();
    }

    private function nom(string $brut, ?EspaceTerrain $sauf = null): string
    {
        $nom = trim($brut);

        if ($nom === '') {
            throw new \DomainException('Le nom du terrain est obligatoire.');
        }

        $existant = $this->espaceRepo->findParNom($nom);

        if ($existant !== null && $existant !== $sauf) {
            throw new \DomainException(sprintf('Le terrain « %s » existe déjà.', $nom));
        }

        return $nom;
    }
}
