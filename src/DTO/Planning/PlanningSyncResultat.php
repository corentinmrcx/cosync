<?php declare(strict_types=1);

namespace App\DTO\Planning;

/**
 * Ce qu'une synchronisation FFF a fait, ou n'a pas pu faire.
 *
 * L'échec est une valeur du résultat et non une exception : l'API fédérale peut refuser
 * la requête depuis le serveur (protection anti-bot), et l'écran doit alors **dire quoi**
 * plutôt que d'afficher une erreur 500. Le planning reste utilisable à la main.
 */
final class PlanningSyncResultat
{
    /**
     * @param list<string> $crees        résumés des matchs ajoutés
     * @param list<string> $misAJour     résumés des matchs dont l'horaire a bougé
     * @param list<string> $supprimes    disparus du flux et restés intacts : effacés
     * @param list<string> $aVerifier    disparus du flux mais annotés ou masqués : conservés
     */
    private function __construct(
        public readonly bool $reussie,
        public readonly array $crees = [],
        public readonly array $misAJour = [],
        public readonly array $supprimes = [],
        public readonly array $aVerifier = [],
        public readonly int $inchanges = 0,
        public readonly ?string $erreur = null,
    ) {}

    /**
     * @param list<string> $crees
     * @param list<string> $misAJour
     * @param list<string> $supprimes
     * @param list<string> $aVerifier
     */
    public static function succes(array $crees, array $misAJour, array $supprimes, array $aVerifier, int $inchanges): self
    {
        return new self(true, $crees, $misAJour, $supprimes, $aVerifier, $inchanges);
    }

    public static function echec(string $erreur): self
    {
        return new self(false, erreur: $erreur);
    }

    public function total(): int
    {
        return count($this->crees) + count($this->misAJour) + count($this->supprimes);
    }

    public function rienAFaire(): bool
    {
        return $this->reussie && $this->total() === 0;
    }

    /** Phrase de retour affichée à l'admin après une synchronisation manuelle. */
    public function resume(): string
    {
        if (!$this->reussie) {
            return (string) $this->erreur;
        }

        if ($this->rienAFaire()) {
            return sprintf('Calendrier déjà à jour — %d match%s inchangé%s.', $this->inchanges, $this->inchanges > 1 ? 's' : '', $this->inchanges > 1 ? 's' : '');
        }

        $parties = [];

        if ($this->crees !== []) {
            $parties[] = sprintf('%d ajouté%s', count($this->crees), count($this->crees) > 1 ? 's' : '');
        }
        if ($this->misAJour !== []) {
            $parties[] = sprintf('%d mis à jour', count($this->misAJour));
        }
        if ($this->supprimes !== []) {
            $parties[] = sprintf('%d retiré%s du calendrier', count($this->supprimes), count($this->supprimes) > 1 ? 's' : '');
        }

        return 'Synchronisation FFF : ' . implode(', ', $parties) . '.';
    }
}
