<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Ce qu'a produit une campagne de renouvellement : ce qui est parti, et ce qui n'a
 * pas pu partir. Les détenteurs sans adresse mail sont nommés plutôt que comptés —
 * l'admin doit savoir qui aller voir avec un papier.
 */
final class CleCampagneResultat
{
    /**
     * @param string[] $sansEmail    noms des détenteurs à relancer autrement
     * @param string[] $echecs       noms des détenteurs dont l'envoi a échoué
     */
    public function __construct(
        public readonly int $envoyes,
        public readonly array $sansEmail = [],
        public readonly array $echecs = [],
    ) {}

    public function rienAFaire(): bool
    {
        return $this->envoyes === 0 && $this->sansEmail === [] && $this->echecs === [];
    }
}
