<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Compte rendu d'un envoi groupé de mails décidé écran en main : liens d'inscription des
 * licenciés, des dirigeants, annonce de la boutique.
 *
 * Les trois catégories qui ne sont pas des envois sont comptées séparément : elles
 * n'appellent pas la même suite. Un licencié sans adresse est à joindre autrement, un
 * licencié décoché attend une décision de l'admin, un échec SMTP est à rejouer.
 */
final class EnvoiGroupeResultat
{
    public function __construct(
        public readonly int $envoyes,
        public readonly int $echecs,
        public readonly int $sansEmail,
        public readonly int $nonRetenus,
    ) {}
}
