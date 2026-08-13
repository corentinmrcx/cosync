<?php declare(strict_types=1);

namespace App\DTO;

final class ImportResultData
{
    public int $created = 0;
    public int $updated = 0;
    /** Répartition des licenciés traités par nature de licence, pour contrôle visuel du rapport. */
    public int $nouveaux = 0;
    public int $renouvellements = 0;
    public int $natureInconnue = 0;
    /** Libellé du format détecté (null si le fichier n'a pas été reconnu). */
    public ?string $layoutLabel = null;
    /**
     * Erreurs bloquantes : la ligne concernée n'a pas été importée.
     *
     * @var list<string>
     */
    public array $errors = [];

    /**
     * Signalements non bloquants (ex. doublon ignoré) : informatifs, rien à corriger.
     *
     * @var list<string>
     */
    public array $notices = [];

    public function addError(int $line, string $message): void
    {
        $this->errors[] = sprintf('Ligne %d : %s', $line, $message);
    }

    public function addNotice(int $line, string $message): void
    {
        $this->notices[] = sprintf('Ligne %d : %s', $line, $message);
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }
}
