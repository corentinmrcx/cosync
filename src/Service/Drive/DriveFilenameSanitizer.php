<?php declare(strict_types=1);

namespace App\Service\Drive;

/**
 * Normalise un fragment de nom de fichier Drive : minuscules ASCII, sans espace ni accent.
 *
 * La translittération est faite à la main, jamais par iconv('ASCII//TRANSLIT') : selon la
 * locale du conteneur, iconv rend « é » sous la forme « 'e » et le nettoyage qui suit
 * transforme l'apostrophe en séparateur — d'où les « R_EGLEMENT_INT_ERIEUR » archivés sur
 * le Drive du club. Une table explicite donne le même résultat partout.
 */
final class DriveFilenameSanitizer
{
    /** @var array<string, string> */
    private const TRANSLITERATION = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ą' => 'a',
        'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ð' => 'd', 'ď' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i',
        'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'œ' => 'oe',
        'ř' => 'r',
        'ś' => 's', 'š' => 's', 'ş' => 's',
        'ß' => 'ss',
        'ţ' => 't', 'ť' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    public function sanitize(string $value): string
    {
        $value = strtr(mb_strtolower($value, 'UTF-8'), self::TRANSLITERATION);
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
