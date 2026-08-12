<?php declare(strict_types=1);

namespace App\Service\Licencie;

use App\DTO\AffectationEquipeApercu;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Team;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use App\Service\Dotation\DotationBesoinSynchronizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rattrapage de l'affectation automatique des équipes.
 *
 * L'import et la création manuelle affectent déjà l'équipe qui couvre la catégorie du
 * licencié, mais seulement si elle existe **à ce moment-là**. Importer avant d'avoir créé
 * les équipes laisse donc tout l'effectif sans équipe, sans autre recours que la saisie
 * une par une : c'est ce que ce service répare.
 *
 * Deux garde-fous, parce que l'opération porte sur tout un effectif d'un seul clic :
 *
 * - seuls les licenciés **sans équipe** sont touchés. Une affectation existante, qu'elle
 *   vienne de l'import ou d'un choix de l'admin, n'est jamais réécrite : c'est ce qui rend
 *   l'opération rejouable sans risque ;
 * - une catégorie couverte par plusieurs équipes (« U15 A » / « U15 B ») ne donne lieu à
 *   aucune affectation — même règle qu'à l'import, on ne devine pas.
 */
final class AffectationEquipeService
{
    public function __construct(
        private readonly LicencieRepository $licencieRepo,
        private readonly TeamRepository $teamRepo,
        private readonly DotationBesoinSynchronizer $dotationSynchronizer,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Ce qui serait fait, sans rien écrire. */
    public function apercu(Season $season): AffectationEquipeApercu
    {
        return $this->calculer($season)['apercu'];
    }

    /** Applique l'affectation et retourne le compte rendu de ce qui a été écrit. */
    public function appliquer(Season $season): AffectationEquipeApercu
    {
        $plan = $this->calculer($season);

        foreach ($plan['affectations'] as [$licencie, $team]) {
            $licencie->setTeam($team);
        }

        $this->em->flush();

        // L'équipe entre dans la résolution des dotations : ce qui est dû peut changer.
        // Le synchronizer ne touche que les besoins « à donner » — une dotation déjà remise
        // reste intacte.
        foreach ($plan['affectations'] as [$licencie]) {
            $this->dotationSynchronizer->recomputeForLicencie($licencie);
        }

        return $plan['apercu'];
    }

    /**
     * @return array{
     *     affectations: list<array{0: Licencie, 1: Team}>,
     *     apercu: AffectationEquipeApercu,
     * }
     */
    private function calculer(Season $season): array
    {
        $equipeParCategorie = $this->teamRepo->mapCategorieVersEquipeUnique($season);

        $affectations = [];
        $parEquipe = [];
        $nonAffectables = [];

        foreach ($this->licencieRepo->findSansEquipe($season) as $licencie) {
            $category = $licencie->getCategory();
            $team = $equipeParCategorie[(int) $category->getId()] ?? null;

            if ($team === null) {
                $code = $category->getCode();
                $nonAffectables[$code] = ($nonAffectables[$code] ?? 0) + 1;

                continue;
            }

            $affectations[] = [$licencie, $team];
            $nom = $team->getName();
            $parEquipe[$nom] = ($parEquipe[$nom] ?? 0) + 1;
        }

        ksort($parEquipe);
        ksort($nonAffectables);

        return [
            'affectations' => $affectations,
            'apercu' => new AffectationEquipeApercu($parEquipe, $nonAffectables),
        ];
    }
}
