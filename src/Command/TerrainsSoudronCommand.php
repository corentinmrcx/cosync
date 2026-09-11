<?php declare(strict_types=1);

namespace App\Command;

use App\Entity\EspaceTerrain;
use App\Repository\EspaceTerrainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reprise du complexe sportif de Soudron dans le référentiel des terrains.
 *
 * Le complexe compte **deux terrains d'entraînement** et un **terrain d'honneur**, ce dernier
 * pouvant se diviser en deux terrains de football à 8. Il n'y a pas de terrain réservé aux
 * seniors : l'honneur sert à tout le monde.
 *
 * Les deux moitiés entrent dans la liste **à côté** de l'entier, sans hiérarchie : la grille
 * ne détecte aucun conflit — deux créneaux au même horaire sont un cas courant — et ce que
 * « moitié 1 » recouvre, c'est le lecteur du document qui le sait.
 *
 * ⚠️ Ceci est une commande, et non une migration, délibérément — même raison que
 * {@see InventaireAout2026Command}. L'inventaire des terrains de Soudron est la donnée d'un
 * club, pas un référentiel qui vaudrait pour toute base : portée par une migration, elle
 * serait rejouée sur chaque base neuve, à commencer par celle de la CI, dont les tests
 * hériteraient de cinq terrains qu'ils n'ont pas posés.
 *
 * Idempotente : chaque terrain n'est créé que s'il n'existe pas déjà sous ce nom. Un terrain
 * renommé depuis l'écran ne sera donc pas reconnu et sera recréé — la commande se lance une
 * fois, au démarrage du module, pas régulièrement.
 */
#[AsCommand(
    name: 'app:occupation:terrains-soudron',
    description: 'Crée les terrains du complexe de Soudron dans le référentiel d\'occupation (idempotent)',
)]
final class TerrainsSoudronCommand extends Command
{
    /** @var list<string> dans l'ordre d'affichage voulu */
    private const TERRAINS = [
        'Terrain d\'honneur',
        'Terrain d\'honneur — moitié 1',
        'Terrain d\'honneur — moitié 2',
        'Terrain d\'entraînement 1',
        'Terrain d\'entraînement 2',
    ];

    public function __construct(
        private readonly EspaceTerrainRepository $espaceRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Terrains du complexe de Soudron');

        $crees = 0;
        $ordre = $this->espaceRepo->prochainOrdre();

        foreach (self::TERRAINS as $nom) {
            if ($this->espaceRepo->findParNom($nom) !== null) {
                $io->text(sprintf('  déjà présent : %s', $nom));

                continue;
            }

            $this->em->persist(
                (new EspaceTerrain())
                    ->setNom($nom)
                    ->setOrdre($ordre++),
            );

            $io->text(sprintf('  créé : %s', $nom));
            ++$crees;
        }

        $this->em->flush();

        $io->success(sprintf('%d terrain(s) créé(s), %d déjà en place.', $crees, count(self::TERRAINS) - $crees));

        return Command::SUCCESS;
    }
}
