<?php declare(strict_types=1);

namespace App\Service\Drive;

/**
 * Normalise un fragment de nom de fichier Drive : ASCII, sans espace ni accent.
 */
final class DriveFilenameSanitizer
{
    public function sanitize(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
