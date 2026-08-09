<?php declare(strict_types=1);

namespace App\Service\Inscription;

/**
 * Autorisations parentales, nulles pour un licencié majeur : la distinction « non applicable »
 * (null) et « refusé » (false) est portée jusqu'en base.
 */
final class AutorisationsJeune
{
    public function __construct(
        public readonly ?bool $transportDirigeants = null,
        public readonly ?bool $transportParents = null,
        public readonly ?bool $accident = null,
        public readonly ?bool $volontaireTransport = null,
    ) {}
}
