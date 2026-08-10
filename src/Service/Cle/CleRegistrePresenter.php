<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\DTO\CleRegistreRow;
use App\DTO\CleRegistreStats;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\CleDetentionStatut;
use App\Repository\AttestationCleRepository;
use App\Repository\DirigeantRepository;

/**
 * Met en forme le registre pour l'écran : rapproche la détention (club) de
 * l'attestation de la saison et de l'effectif de la saison. N'écrit rien.
 *
 * Les statistiques portent toujours sur l'ensemble du registre, jamais sur le
 * résultat filtré : « 3 attestations à signer » doit rester vrai pendant qu'on
 * cherche quelqu'un.
 */
final class CleRegistrePresenter
{
    public function __construct(
        private readonly CleRegistreService $registre,
        private readonly AttestationCleRepository $attestationRepo,
        private readonly DetenteurEffectifResolver $effectifResolver,
        private readonly DirigeantRepository $dirigeantRepo,
    ) {}

    /**
     * Toutes les lignes du registre pour la saison affichée, triées par nom.
     *
     * @return CleRegistreRow[]
     */
    public function lignes(Season $season): array
    {
        $detentions = $this->registre->getDetentions();
        $detenteurs = array_map(static fn ($detention) => $detention->detenteur, $detentions);

        $effectif = $this->effectifResolver->pourSaison($season, $detenteurs);
        $attestations = $this->attestationRepo->findDernieresParDetenteur($season);

        return array_map(
            static fn ($detention): CleRegistreRow => new CleRegistreRow(
                detention: $detention,
                dirigeantSaison: $effectif[$detention->detenteur->getId()] ?? null,
                attestation: $attestations[$detention->detenteur->getId()] ?? null,
            ),
            $detentions,
        );
    }

    /**
     * La ligne de registre d'un dirigeant, pour sa fiche — null s'il ne détient rien
     * et n'a jamais rien détenu.
     */
    public function pourDirigeant(Dirigeant $dirigeant): ?CleRegistreRow
    {
        $detenteur = $this->effectifResolver->detenteurDe($dirigeant);

        if ($detenteur === null) {
            return null;
        }

        return new CleRegistreRow(
            detention: $this->registre->getDetentionDe($detenteur),
            dirigeantSaison: $dirigeant,
            attestation: $this->attestationRepo->findDerniereDe($detenteur, $dirigeant->getSeason()),
        );
    }

    /**
     * Lignes restreintes à ce que l'admin cherche.
     *
     * @param CleRegistreRow[] $lignes
     *
     * @return CleRegistreRow[]
     */
    public function filtrer(array $lignes, string $recherche = '', ?CleDetentionStatut $statut = null): array
    {
        if ($recherche !== '') {
            $recherche = mb_strtolower($recherche);
            $lignes = array_filter(
                $lignes,
                static fn (CleRegistreRow $ligne): bool => str_contains(
                    mb_strtolower($ligne->detenteur()->getNomPrenom()),
                    $recherche,
                ),
            );
        }

        if ($statut !== null) {
            $lignes = array_filter(
                $lignes,
                static fn (CleRegistreRow $ligne): bool => match ($statut) {
                    CleDetentionStatut::DETENTEUR => $ligne->estDetenteur(),
                    CleDetentionStatut::SIGNATURE_MANQUANTE => $ligne->attendSignature(),
                    CleDetentionStatut::HORS_EFFECTIF => $ligne->horsEffectif(),
                    CleDetentionStatut::RESTITUE => !$ligne->estDetenteur(),
                },
            );
        }

        return array_values($lignes);
    }

    /** @param CleRegistreRow[] $lignes toutes les lignes, non filtrées */
    public function stats(array $lignes): CleRegistreStats
    {
        $enCirculation = 0;
        $detenteurs = 0;
        $perdues = 0;
        $restituees = 0;
        $horsEffectif = 0;
        $signees = 0;

        foreach ($lignes as $ligne) {
            $perdues += $ligne->detention->pertes;
            $restituees += $ligne->detention->restitutions;

            if (!$ligne->estDetenteur()) {
                continue;
            }

            $enCirculation += $ligne->detention->solde;
            ++$detenteurs;

            if ($ligne->horsEffectif()) {
                ++$horsEffectif;
            }

            if ($ligne->attestationAJour()) {
                ++$signees;
            }
        }

        return new CleRegistreStats(
            clesEnCirculation: $enCirculation,
            nbDetenteurs: $detenteurs,
            clesPerdues: $perdues,
            clesRestituees: $restituees,
            nbHorsEffectif: $horsEffectif,
            nbAttestationsSignees: $signees,
            nbAttestationsManquantes: $detenteurs - $signees,
        );
    }

    /**
     * À qui remettre une clé : les personnes déjà au registre, puis les dirigeants de
     * la saison qui n'y figurent pas encore. Désigner l'un de ces derniers l'inscrit
     * au registre au moment du mouvement — pas d'écran intermédiaire.
     *
     * @param CleRegistreRow[] $lignes toutes les lignes, non filtrées
     *
     * @return array{detenteurs: list<array{reference: string, label: string}>, dirigeants: list<array{reference: string, label: string}>}
     */
    public function candidats(Season $season, array $lignes): array
    {
        $dejaAuRegistre = [];

        foreach ($lignes as $ligne) {
            if ($ligne->dirigeantSaison !== null) {
                $dejaAuRegistre[(string) $ligne->dirigeantSaison->getUuid()] = true;
            }
        }

        $detenteurs = array_map(
            static fn (CleRegistreRow $ligne): array => [
                'reference' => 'detenteur:' . $ligne->detenteur()->getId(),
                'label' => $ligne->detenteur()->getNomPrenom(),
            ],
            $lignes,
        );

        $dirigeants = [];

        foreach ($this->dirigeantRepo->findBySeason($season) as $dirigeant) {
            if (!isset($dejaAuRegistre[(string) $dirigeant->getUuid()])) {
                $dirigeants[] = [
                    'reference' => 'dirigeant:' . $dirigeant->getUuid(),
                    'label' => $dirigeant->getNomPrenom(),
                ];
            }
        }

        return ['detenteurs' => array_values($detenteurs), 'dirigeants' => $dirigeants];
    }

    /**
     * Détenteurs actuels dont l'engagement de la saison ne couvre pas la détention.
     * C'est exactement la cible d'une campagne de renouvellement.
     *
     * @param CleRegistreRow[] $lignes
     *
     * @return CleRegistreRow[]
     */
    public function enAttenteDeSignature(array $lignes): array
    {
        return array_values(array_filter(
            $lignes,
            static fn (CleRegistreRow $ligne): bool => $ligne->attendSignature(),
        ));
    }
}
