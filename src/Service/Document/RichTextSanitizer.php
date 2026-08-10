<?php declare(strict_types=1);

namespace App\Service\Document;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Assainit le HTML riche saisi en administration (éditeur Quill) avant de le stocker.
 *
 * Ce contenu est rendu `|raw` sur des pages publiques non authentifiées (formulaire
 * d'inscription, signature d'attestation) et dans les PDF. Le nettoyage fait par Quill
 * est purement côté client, donc contournable par un POST direct : la seule barrière
 * fiable est une whitelist serveur, appliquée à l'écriture. On n'autorise que les
 * balises et attributs réellement produits par l'éditeur.
 */
final class RichTextSanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p', ['class'])
            ->allowElement('span', ['class'])
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('ul')
            ->allowElement('ol', ['class'])
            ->allowElement('li', ['class'])
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('blockquote')
            ->allowElement('a', ['href', 'title'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(200_000);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function assainir(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return $this->sanitizer->sanitize($html);
    }
}
