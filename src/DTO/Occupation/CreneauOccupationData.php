<?php declare(strict_types=1);

namespace App\DTO\Occupation;

use App\Enum\JourSemaine;
use App\Enum\UsageOccupation;

/**
 * Ce que la modale de saisie envoie : un jour, deux horaires, une équipe, un terrain, un usage.
 *
 * Aucun champ n'est une zone de texte libre — le jour, l'usage, l'équipe et le terrain sont
 * des sélecteurs, et les horaires sortent du glissé sur la grille ou du sélecteur d'heure du
 * navigateur. C'est `CreneauOccupationService` qui décide de ce qui est recevable ; ce DTO
 * ne fait que porter la saisie.
 */
final readonly class CreneauOccupationData
{
    public function __construct(
        public ?JourSemaine $jour,
        public string $heureDebut,
        public string $heureFin,
        public ?int $equipeId,
        public ?int $espaceId,
        public ?UsageOccupation $usage,
    ) {}
}
