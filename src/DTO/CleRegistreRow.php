<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\AttestationCle;
use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Enum\CleAttestationEtat;

/**
 * Une ligne du registre : ce que la personne détient (hors saison) rapproché de son
 * engagement pour la saison affichée (par saison) et de sa place à l'effectif.
 *
 * C'est le seul endroit où les deux échelles de temps se rencontrent — le registre
 * les ignore l'une comme l'autre, et c'est ce qui le garde juste.
 */
final class CleRegistreRow
{
    public function __construct(
        public readonly CleDetention $detention,
        /** Le dirigeant correspondant dans la saison affichée — null si la personne n'est plus à l'effectif */
        public readonly ?Dirigeant $dirigeantSaison,
        /** La dernière attestation de la saison affichée — null si aucune n'a jamais été demandée */
        public readonly ?AttestationCle $attestation,
        /**
         * Ce que la personne fait dans le club, tel qu'on l'écrit à un tiers — résolu par
         * {@see \App\Service\Cle\DetenteurFonctionResolver}. Calculé ici plutôt que dans
         * chaque template : l'écran et le récapitulatif de la mairie doivent dire la même
         * chose, faute de quoi l'un annonce « Bureau du foot » pendant que l'autre nomme.
         */
        public readonly string $fonction = '',
        /**
         * Cette personne appartient-elle au club, toutes saisons confondues ? Distinct de
         * `dirigeantSaison`, qui ne parle que de la saison affichée : un ex-dirigeant en est
         * absent, et sa fiche n'en reste pas moins tenue par l'effectif dès qu'un import le
         * ramène. C'est ce fait-là, et non celui de la saison, qui décide si l'identité se
         * corrige au registre.
         */
        public readonly bool $rattacheALEffectif = false,
    ) {}

    public function detenteur(): Detenteur
    {
        return $this->detention->detenteur;
    }

    public function estDetenteur(): bool
    {
        return $this->detention->estDetenteur();
    }

    /**
     * Détient des clés sans figurer à l'effectif de la saison : quelqu'un est parti
     * sans rendre son trousseau. Le registre le garde visible plutôt que de le faire
     * disparaître — ce sont des clés réellement dehors.
     */
    public function horsEffectif(): bool
    {
        return $this->estDetenteur() && $this->dirigeantSaison === null;
    }

    public function etatAttestation(): CleAttestationEtat
    {
        if ($this->attestation === null) {
            return CleAttestationEtat::NON_SIGNEE;
        }

        if (!$this->attestation->estSignee()) {
            return $this->attestation->isTokenValid()
                ? CleAttestationEtat::LIEN_ENVOYE
                : CleAttestationEtat::NON_SIGNEE;
        }

        return $this->nombreAtteste() ? CleAttestationEtat::SIGNEE : CleAttestationEtat::A_RENOUVELER;
    }

    /** L'engagement de la saison couvre-t-il la détention actuelle ? */
    public function attestationAJour(): bool
    {
        return $this->etatAttestation()->estAJour();
    }

    /** Une attestation n'a plus à être demandée si elle est à jour, ou si la personne ne détient rien. */
    public function attendSignature(): bool
    {
        return $this->estDetenteur() && !$this->attestationAJour();
    }

    public function signedAt(): ?\DateTimeImmutable
    {
        return $this->attestation?->getSignedAt();
    }

    /**
     * Le nombre de clés attesté correspond-il toujours à la réalité ? Une remise
     * postérieure à la signature le périme ; une restitution, non — rendre une clé
     * ne demande pas de re-signer un engagement plus large que la détention.
     */
    private function nombreAtteste(): bool
    {
        $signedAt = $this->attestation?->getSignedAt();
        $derniereRemise = $this->detention->derniereRemiseLe;

        if ($signedAt === null || $derniereRemise === null) {
            return true;
        }

        return $derniereRemise <= $signedAt;
    }
}
