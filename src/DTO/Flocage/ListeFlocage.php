<?php declare(strict_types=1);

namespace App\DTO\Flocage;

/**
 * La consigne d'atelier qu'on remet au floqueur.
 *
 * C'est le même travail que l'écran `admin/dotations/flocage`, débarrassé de ce qui ne regarde
 * que le club : le porteur et son équipe. Le floqueur ne pose pas la question « pour qui ? »,
 * il pose « quel article, quelle taille, quel texte ». Le nom d'un licencié n'a donc rien à
 * faire sur un document qui sort du club (RGPD, §6) — et sur un vêtement, le texte à marquer
 * n'est même pas toujours le nom de celui qui le portera.
 */
final class ListeFlocage
{
    /** @param list<FlocageArticle> $articles */
    public function __construct(
        public readonly string $saisonLabel,
        public readonly \DateTimeImmutable $editeLe,
        public readonly array $articles,
        public readonly int $pieces,
    ) {}
}
