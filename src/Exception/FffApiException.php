<?php declare(strict_types=1);

namespace App\Exception;

/**
 * L'API fédérale n'a pas répondu ce qu'on attendait.
 *
 * Le cas à ne pas confondre avec une panne : la FFF sert son calendrier derrière une
 * protection anti-bot qui refuse les clients non navigateurs. Un serveur peut donc
 * recevoir un **403 permanent** là où le même appel passe depuis un poste de travail.
 * D'où `estRefusParProtection()` : l'écran doit dire « la FFF refuse les appels depuis
 * le serveur », pas « service indisponible, réessayez » — il n'y a rien à réessayer.
 */
class FffApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $codeHttp = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getCodeHttp(): ?int
    {
        return $this->codeHttp;
    }

    public function estRefusParProtection(): bool
    {
        return $this->codeHttp === 403;
    }

    /**
     * Le numéro ne correspond à aucun club. C'est la seule erreur qui met en cause la
     * saisie de l'admin — toutes les autres mettent en cause l'accès au service, et le
     * dire clairement évite de chercher un numéro qui était bon.
     */
    public function estClubIntrouvable(): bool
    {
        return $this->codeHttp === 404;
    }

    /** Message destiné à l'admin, qui doit savoir s'il peut agir ou non. */
    public function messageAdmin(): string
    {
        if ($this->estClubIntrouvable()) {
            return 'Aucun club ne porte ce numéro dans le référentiel fédéral. '
                . 'Attention : le numéro attendu n\'est pas le numéro d\'affiliation du club.';
        }

        if ($this->estRefusParProtection()) {
            return 'Le service fédéral refuse les appels venant du serveur (HTTP 403, protection anti-robot). '
                . 'Ce n\'est pas lié au numéro saisi : aucune vérification ni mise à jour automatique '
                . 'ne peut aboutir depuis cet hébergement. Les matchs se saisissent à la main ou par collage.';
        }

        return 'Service fédéral injoignable : ' . $this->getMessage();
    }
}
