<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Niveau d'alerte d'un article au regard de son seuil.
 *
 * Vocabulaire distinct de celui des messages flash : ici il s'agit de l'état du stock,
 * pas de la gravité d'une notification.
 */
enum StockAlerteNiveau: string
{
    case OK = 'ok';
    case BAS = 'warning';
    case RUPTURE = 'danger';

    public static function pour(int $stock, ?int $seuil): self
    {
        if ($seuil === null) {
            return self::OK;
        }

        return match (true) {
            $stock <= 0 => self::RUPTURE,
            $stock <= $seuil => self::BAS,
            default => self::OK,
        };
    }

    public function estAlerte(): bool
    {
        return $this !== self::OK;
    }

    public function label(): string
    {
        return match ($this) {
            self::OK => '',
            self::BAS => 'Stock bas',
            self::RUPTURE => 'Rupture',
        };
    }
}
