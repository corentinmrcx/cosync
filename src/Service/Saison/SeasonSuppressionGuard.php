<?php declare(strict_types=1);

namespace App\Service\Saison;

use App\Entity\AttestationCle;
use App\Entity\Commande;
use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Entity\DotationAffectation;
use App\Entity\DotationBesoin;
use App\Entity\DotationModele;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Transaction;
use App\Repository\SeasonRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qui autorise — ou interdit — la suppression d'une saison.
 *
 * Source de vérité unique : le contrôleur et l'écran de gestion lisent ce service, aucun
 * des deux ne recompte. Auparavant la règle était écrite deux fois, et la version du
 * template ignorait tout ce qui n'était ni licencié ni dirigeant.
 */
final class SeasonSuppressionGuard
{
    /**
     * Toutes les tables qui référencent `season` sans `ON DELETE` : oublier l'une d'elles,
     * c'est laisser passer une suppression qui casse ensuite sur la clé étrangère.
     *
     * @var array<class-string, array{string, string}> classe => [singulier, pluriel]
     */
    private const RATTACHEMENTS = [
        Licencie::class => ['licencié', 'licenciés'],
        Dirigeant::class => ['dirigeant', 'dirigeants'],
        Team::class => ['équipe', 'équipes'],
        Transaction::class => ['transaction', 'transactions'],
        DocumentSignable::class => ['document', 'documents'],
        DotationModele::class => ['modèle de dotation', 'modèles de dotation'],
        DotationBesoin::class => ['besoin de dotation', 'besoins de dotation'],
        DotationAffectation::class => ['affectation de dotation', 'affectations de dotation'],
        Commande::class => ['commande', 'commandes'],
        // Le registre des clés, lui, ne dépend plus de la saison : seules les
        // attestations signées dans l'année la référencent encore.
        AttestationCle::class => ['attestation de clés', 'attestations de clés'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeasonRepository $seasonRepo,
    ) {}

    /**
     * Ce que la saison contient, accordé en nombre et prêt à afficher.
     *
     * @return array<string, int> libellé => nombre ; vide quand rien n'est rattaché
     */
    public function rattachements(Season $season): array
    {
        $rattachements = [];

        foreach (self::RATTACHEMENTS as $classe => [$singulier, $pluriel]) {
            $nombre = $this->em->getRepository($classe)->count(['season' => $season]);

            if ($nombre > 0) {
                $rattachements[$nombre > 1 ? $pluriel : $singulier] = $nombre;
            }
        }

        return $rattachements;
    }

    /**
     * La saison sur laquelle basculer si l'on supprime celle-ci — supprimer la saison de
     * travail est permis, s'y retrouver sans saison ne l'est pas.
     */
    public function remplacantePour(Season $season): ?Season
    {
        foreach ($this->seasonRepo->findAllOrdered() as $candidate) {
            if ($candidate->getId() !== $season->getId()) {
                return $candidate;
            }
        }

        return null;
    }

    public function peutSupprimer(Season $season): bool
    {
        return $this->raison($season) === null;
    }

    /** Pourquoi la suppression est refusée, ou null si elle est possible. */
    public function raison(Season $season): ?string
    {
        $rattachements = $this->rattachements($season);

        if ($rattachements !== []) {
            $morceaux = [];
            foreach ($rattachements as $libelle => $nombre) {
                $morceaux[] = $nombre . ' ' . $libelle;
            }

            return 'Contient ' . implode(', ', $morceaux);
        }

        if ($this->remplacantePour($season) === null) {
            return 'Dernière saison : le club doit toujours en avoir une';
        }

        return null;
    }
}
