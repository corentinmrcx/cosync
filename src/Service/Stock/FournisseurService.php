<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\FournisseurData;
use App\Entity\Fournisseur;
use Doctrine\ORM\EntityManagerInterface;

final class FournisseurService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** @throws \DomainException si le nom est vide */
    public function creer(FournisseurData $data): Fournisseur
    {
        if ($data->nom === null) {
            throw new \DomainException('Le nom du fournisseur est obligatoire.');
        }

        $fournisseur = (new Fournisseur())
            ->setNom($data->nom)
            ->setContact($data->contact)
            ->setEmail($data->email);

        $this->em->persist($fournisseur);
        $this->em->flush();

        return $fournisseur;
    }

    /** Un nom vide laisse l'ancien en place plutôt que d'effacer l'identité du fournisseur. */
    public function mettreAJour(Fournisseur $fournisseur, FournisseurData $data): void
    {
        if ($data->nom !== null) {
            $fournisseur->setNom($data->nom);
        }

        $fournisseur
            ->setContact($data->contact)
            ->setEmail($data->email)
            ->setActif($data->actif);

        $this->em->flush();
    }

    public function supprimer(Fournisseur $fournisseur): void
    {
        $this->em->remove($fournisseur);
        $this->em->flush();
    }
}
