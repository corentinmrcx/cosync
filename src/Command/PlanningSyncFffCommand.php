<?php declare(strict_types=1);

namespace App\Command;

use App\DTO\Planning\PlanningSyncResultat;
use App\Repository\SeasonRepository;
use App\Service\Planning\Fff\FffSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:planning:sync-fff',
    description: 'Aligne le planning des matchs à domicile sur le calendrier du district',
)]
final class PlanningSyncFffCommand extends Command
{
    public function __construct(
        private readonly FffSyncService $syncService,
        private readonly SeasonRepository $seasonRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui changerait, sans rien écrire')
            ->addOption('saison', null, InputOption::VALUE_REQUIRED, 'Libellé de la saison à traiter (défaut : la plus récente)');
    }

    /**
     * Passe quotidienne. Sans effet tant que la synchronisation automatique n'est pas
     * activée dans les réglages : l'automate s'installe par un déploiement, il s'allume
     * par une décision.
     *
     * `--dry-run` court-circuite l'interrupteur — c'est même sa raison d'être. Il répond
     * à la question qu'on ne peut pas trancher depuis un poste de travail : **l'API FFF
     * accepte-t-elle les appels venant de ce serveur ?** Elle sert son calendrier derrière
     * une protection anti-robot qui peut refuser un hébergeur. Un `403` ici veut dire que
     * la synchronisation ne marchera jamais depuis cette machine — le planning se remplit
     * alors à la main ou par collage, et l'interrupteur reste éteint.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');

        $libelle = $input->getOption('saison');
        $season = \is_string($libelle)
            ? $this->seasonRepository->findOneBy(['label' => $libelle])
            : $this->seasonRepository->findMostRecent();

        if ($season === null) {
            $io->error(\is_string($libelle) ? sprintf('Saison « %s » introuvable.', $libelle) : 'Aucune saison en base.');

            return Command::FAILURE;
        }

        if (!$this->syncService->estConfigure()) {
            $io->warning('Aucun numéro de club FFF renseigné — rien à synchroniser.');

            return Command::SUCCESS;
        }

        if (!$simulation && !$this->syncService->estAutomatique()) {
            $io->text('Synchronisation automatique désactivée — rien à faire.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Calendrier FFF — saison %s%s', $season->getLabel(), $simulation ? ' (simulation)' : ''));

        $resultat = $this->syncService->synchroniser($season, $simulation);

        if (!$resultat->reussie) {
            $io->error((string) $resultat->erreur);

            return Command::FAILURE;
        }

        $this->afficher($io, $resultat);

        return Command::SUCCESS;
    }

    private function afficher(SymfonyStyle $io, PlanningSyncResultat $resultat): void
    {
        $this->liste($io, 'Ajoutés', $resultat->crees);
        $this->liste($io, 'Mis à jour (horaire ou adversaire modifié)', $resultat->misAJour);
        $this->liste($io, 'Retirés du calendrier fédéral', $resultat->supprimes);

        // Ces lignes-là demandent une décision humaine : le club y a mis une note ou les a
        // masquées, et elles ont disparu du flux. Les taire les laisserait sur le planning.
        $this->liste($io, 'Disparus du calendrier mais conservés (annotés ou masqués) — à vérifier', $resultat->aVerifier);

        $io->success($resultat->resume());
        $io->text(sprintf('%d match(s) déjà à jour.', $resultat->inchanges));
    }

    /** @param list<string> $lignes */
    private function liste(SymfonyStyle $io, string $titre, array $lignes): void
    {
        if ($lignes === []) {
            return;
        }

        $io->section(sprintf('%s (%d)', $titre, count($lignes)));
        $io->listing($lignes);
    }
}
