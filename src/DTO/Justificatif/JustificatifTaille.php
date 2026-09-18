<?php declare(strict_types=1);

namespace App\DTO\Justificatif;

/**
 * Le détail d'une taille, sous la ligne de son article.
 *
 * N'existe que pour répondre au seul cas où la ligne d'article surprend : « tu as 5 vestes et
 * tu en recommandes 5 ? » — oui, parce qu'elles sont en L et qu'il les faut en M.
 */
final class JustificatifTaille
{
    public function __construct(
        public readonly string $etiquette,
        public readonly int $ilEnFaut,
        public readonly int $onEnA,
        public readonly int $aCommander,
    ) {}
}
