<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\DotationModeleLigne;

/**
 * Ce qu'un profil de personne recevra réellement d'un kit, une fois l'éligibilité appliquée.
 * Rendu tel quel dans l'écran d'édition : le Twig n'a aucun calcul à faire.
 */
final class DotationApercuProfil
{
    /**
     * @param DotationModeleLigne[]                                             $fixes     articles reçus par tous, sans question
     * @param array<int, array{groupe: string, ligne: DotationModeleLigne}>     $imposes   groupe tranché d'office pour ce profil
     * @param array<int, array{groupe: string, options: DotationModeleLigne[]}> $questions choix réellement posés au licencié
     * @param string[]                                                          $alertes   ce qui mérite l'attention de l'admin
     */
    public function __construct(
        public readonly string $nom,
        public readonly string $description,
        public readonly array $fixes,
        public readonly array $imposes,
        public readonly array $questions,
        public readonly array $alertes,
    ) {}

    public function neRecoitRien(): bool
    {
        return $this->fixes === [] && $this->imposes === [] && $this->questions === [];
    }
}
