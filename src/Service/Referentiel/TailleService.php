<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\Entity\Taille;
use App\Repository\DirigeantRepository;
use App\Repository\DossierClubRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\GrilleTailleValeurRepository;
use App\Repository\StockMovementRepository;
use App\Repository\StockTailleNoteRepository;
use App\Repository\TailleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écriture du référentiel des tailles.
 *
 * Le libellé d'une taille est recopié tel quel dans les dossiers, les mouvements de stock et
 * les besoins de dotation : le renommer ou le supprimer alors qu'il sert quelque part
 * laisserait des lignes désignant une taille qui n'existe plus. Ces deux gestes sont donc
 * refusés tant que la taille est employée — le reste (groupe, ordre, formulaires) se règle
 * librement.
 */
final class TailleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TailleRepository $repository,
        private readonly TailleReferentiel $referentiel,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockTailleNoteRepository $noteRepository,
        private readonly DossierClubRepository $dossierRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly GrilleTailleValeurRepository $grilleValeurRepository,
    ) {}

    /**
     * @throws \DomainException si la taille existe déjà pour ce type
     */
    public function creer(Taille $taille): void
    {
        $taille->setLibelle(trim($taille->getLibelle()));
        $this->assertLibelleLibre($taille);

        $taille->setPosition($this->repository->dernierePosition() + 1);

        $this->em->persist($taille);
        $this->em->flush();
        $this->referentiel->oublier();
    }

    /**
     * @throws \DomainException si le libellé change alors que la taille est employée,
     *                          ou si le nouveau libellé est déjà pris
     */
    public function modifier(Taille $taille, string $libelle, ?string $groupe, bool $proposee): void
    {
        $libelle = trim($libelle);

        if ($libelle !== $taille->getLibelle()) {
            $employee = $this->compterUtilisations($taille);
            if ($employee > 0) {
                throw new \DomainException(sprintf('Impossible de renommer « %s » : %d enregistrement%s la désigne%s déjà. Créez une nouvelle taille si besoin.', $taille->getLibelle(), $employee, $employee > 1 ? 's' : '', $employee > 1 ? 'nt' : ''));
            }

            $taille->setLibelle($libelle);
            $this->assertLibelleLibre($taille);
        }

        $taille->setGroupe(trim((string) $groupe) ?: null);
        $taille->setProposeeAuxLicencies($proposee);

        $this->em->flush();
        $this->referentiel->oublier();
    }

    /**
     * @throws \DomainException si la taille est employée quelque part
     */
    public function supprimer(Taille $taille): void
    {
        $employee = $this->compterUtilisations($taille);
        if ($employee > 0) {
            throw new \DomainException(sprintf('Impossible de supprimer « %s » : %d enregistrement%s la désigne%s. Décochez-la des formulaires pour ne plus la proposer.', $taille->getLibelle(), $employee, $employee > 1 ? 's' : '', $employee > 1 ? 'nt' : ''));
        }

        $this->em->remove($taille);
        $this->em->flush();
        $this->referentiel->oublier();
    }

    /**
     * Réordonne le référentiel selon la liste d'identifiants reçue. Les tailles absentes de
     * la liste sont reléguées à la suite : un onglet resté ouvert pendant qu'une taille était
     * créée ailleurs ne doit en faire disparaître aucune.
     *
     * @param int[] $idsOrdonnes
     */
    public function reordonner(array $idsOrdonnes): void
    {
        $parId = [];
        foreach ($this->repository->findAllOrdered() as $taille) {
            $parId[$taille->getId()] = $taille;
        }

        $position = 0;
        foreach ($idsOrdonnes as $id) {
            $taille = $parId[$id] ?? null;
            if ($taille === null) {
                continue;
            }

            $taille->setPosition($position++);
            unset($parId[$id]);
        }

        foreach ($parId as $restante) {
            $restante->setPosition($position++);
        }

        $this->em->flush();
        $this->referentiel->oublier();
    }

    /**
     * Libellés employés quelque part, en clés. Six requêtes pour tout le référentiel — la
     * liste d'admin n'a besoin que de savoir *si* une taille est employée, pas combien de
     * fois, et un comptage par ligne en aurait demandé six par taille.
     *
     * @return array<string, true>
     */
    public function libellesEmployes(): array
    {
        $employes = [];
        foreach ([
            $this->movementRepository->findDistinctTailles(),
            $this->noteRepository->findDistinctTailles(),
            $this->dossierRepository->findDistinctTailles(),
            $this->dirigeantRepository->findDistinctTailles(),
            $this->besoinRepository->findDistinctTailles(),
            $this->grilleValeurRepository->findDistinctTailles(),
        ] as $libelles) {
            foreach ($libelles as $libelle) {
                $employes[$libelle] = true;
            }
        }

        return $employes;
    }

    /** Nombre d'enregistrements qui désignent cette taille, tous domaines confondus. */
    public function compterUtilisations(Taille $taille): int
    {
        $libelle = $taille->getLibelle();

        return $this->movementRepository->countByTaille($libelle)
            + $this->noteRepository->countByTaille($libelle)
            + $this->dossierRepository->countByTaille($libelle)
            + $this->dirigeantRepository->countByTaille($libelle)
            + $this->besoinRepository->countByTaille($libelle)
            // Une grille traduit vers ce libellé ou l'englobe : le supprimer ferait tomber la
            // traduction, et le renommer la ferait pointer vers une taille qui n'existe plus.
            + $this->grilleValeurRepository->countByTaille($libelle);
    }

    /**
     * @throws \DomainException
     */
    private function assertLibelleLibre(Taille $taille): void
    {
        if ($taille->getLibelle() === '') {
            throw new \DomainException('Le libellé d\'une taille ne peut pas être vide.');
        }

        $existante = $this->repository->findOneByLibelle($taille->getType(), $taille->getLibelle());
        if ($existante !== null && $existante !== $taille) {
            throw new \DomainException(sprintf('La taille « %s » existe déjà en %s.', $taille->getLibelle(), mb_strtolower($taille->getType()->label())));
        }
    }
}
