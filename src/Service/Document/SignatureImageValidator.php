<?php declare(strict_types=1);

namespace App\Service\Document;

/**
 * Valide une signature manuscrite postée par un formulaire public.
 *
 * Signature_pad.js produit exclusivement du PNG en data URI : on n'accepte que ce format,
 * et on va jusqu'à décoder le contenu pour vérifier que ce sont bien des octets PNG. Se
 * contenter du préfixe `data:image/` laisserait passer un SVG (bombe XML, référence de
 * fichier locale rendue par DomPDF) ou une chaîne base64 factice non probante.
 */
final class SignatureImageValidator
{
    /** Un canvas signé dépasse rarement 300 Ko ; au-delà, la requête est suspecte. */
    private const TAILLE_MAX = 2_800_000;

    private const PREFIXE = 'data:image/png;base64,';

    /** Un pad de signature ne dépasse jamais ces dimensions ; borne anti-bombe de décompression. */
    private const DIMENSION_MAX = 5000;

    public function estValide(mixed $signature): bool
    {
        if (!is_string($signature) || $signature === '' || strlen($signature) > self::TAILLE_MAX) {
            return false;
        }

        if (!str_starts_with($signature, self::PREFIXE)) {
            return false;
        }

        $binaire = base64_decode(substr($signature, strlen(self::PREFIXE)), true);

        if ($binaire === false || $binaire === '') {
            return false;
        }

        $infos = @getimagesizefromstring($binaire);

        if ($infos === false || $infos[2] !== IMAGETYPE_PNG) {
            return false;
        }

        return $infos[0] > 0 && $infos[1] > 0
            && $infos[0] <= self::DIMENSION_MAX
            && $infos[1] <= self::DIMENSION_MAX;
    }
}
