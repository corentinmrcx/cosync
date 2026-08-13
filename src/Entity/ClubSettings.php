<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglages du club, communs à toutes les saisons. Table à ligne unique : le club change de
 * banque, pas de RIB par saison.
 *
 * Les colonnes iban / bic / titulaire_compte de `season` ont porté ces valeurs jusqu'à
 * Version20260809160000, qui les recopie ici. Elles subsistent en base, dé-mappées, le temps
 * de valider la bascule en production.
 */
#[ORM\Entity(repositoryClass: ClubSettingsRepository::class)]
#[ORM\Table(name: 'club_settings')]
class ClubSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $titulaireCompte = null;

    /**
     * Lien de la boutique du club (HelloAsso). Réglage du club et non de la saison : la
     * boutique est une page de l'association, elle ne se recrée pas à chaque rentrée.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $boutiqueUrl = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        // Un champ vidé arrive en chaîne vide : la stocker laisserait accepteVirement()
        // répondre vrai et le formulaire public proposerait un virement sans IBAN.
        $this->iban = $this->normaliser($iban);

        return $this;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): static
    {
        $this->bic = $this->normaliser($bic);

        return $this;
    }

    public function getTitulaireCompte(): ?string
    {
        return $this->titulaireCompte;
    }

    public function setTitulaireCompte(?string $titulaireCompte): static
    {
        $this->titulaireCompte = $this->normaliser($titulaireCompte);

        return $this;
    }

    public function getBoutiqueUrl(): ?string
    {
        return $this->boutiqueUrl;
    }

    public function setBoutiqueUrl(?string $boutiqueUrl): static
    {
        $this->boutiqueUrl = $this->normaliser($boutiqueUrl);

        return $this;
    }

    /** Sans IBAN, l'option « virement » disparaît du formulaire public. */
    public function accepteVirement(): bool
    {
        return $this->iban !== null;
    }

    /** Sans lien renseigné, la boutique n'est annoncée nulle part — ni page, ni mail. */
    public function aBoutique(): bool
    {
        return $this->boutiqueUrl !== null;
    }

    private function normaliser(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }
}
