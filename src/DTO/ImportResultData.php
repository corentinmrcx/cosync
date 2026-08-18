<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\StatutDossierFff;

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

    /**
     * Lignes écartées d'après la colonne « Statut », regroupées par libellé brut de l'export.
     * Regroupé et non listé ligne à ligne : un export non filtré en écarte des dizaines, une
     * liste nominative noierait le rapport au lieu de l'éclairer.
     *
     * @var array<string, int> libellé tel qu'écrit par FootClubs => nombre de lignes
     */
    public array $ignoresParStatut = [];

    /**
     * Libellés de statut que CoSync n'a pas su interpréter. S'ils apparaissent, c'est que
     * l'export a changé : le rapport doit le dire, sinon un effectif entier serait écarté
     * en silence.
     *
     * @var list<string>
     */
    public array $statutsInconnus = [];

    public function addIgnore(string $statutBrut, ?StatutDossierFff $statut): void
    {
        $libelle = trim($statutBrut) !== '' ? trim($statutBrut) : 'statut vide';

        $this->ignoresParStatut[$libelle] = ($this->ignoresParStatut[$libelle] ?? 0) + 1;

        if ($statut === null && !in_array($libelle, $this->statutsInconnus, true)) {
            $this->statutsInconnus[] = $libelle;
        }
    }

    public function ignores(): int
    {
        return array_sum($this->ignoresParStatut);
    }

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
