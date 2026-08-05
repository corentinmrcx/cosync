<?php declare(strict_types=1);

namespace App\DTO;

/** Intention de paiement créée chez HelloAsso : identifiant à conserver + URL vers laquelle rediriger le payeur. */
final class HelloAssoCheckoutIntent
{
    public function __construct(
        public readonly string $id,
        public readonly string $redirectUrl,
    ) {}
}
