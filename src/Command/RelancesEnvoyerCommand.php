<?php declare(strict_types=1);

namespace App\Command;

use App\DTO\RelanceDue;
use App\Repository\SeasonRepository;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Relance\RelanceResolver;
use App\Service\Relance\RelanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:relances:envoyer',
    description: 'Relance par mail les licences non soldées dont le dernier contact remonte au délai configuré',
)]
final class RelancesEnvoyerCommand extends Command
{
    public function __construct(
        private readonly RelanceResolver $resolver,
        private readonly RelanceService $relanceService,
        private readonly ClubSettingsService $clubSettings,
        private readonly SeasonRepository $seasonRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche qui serait relancé, sans envoyer aucun mail')
            ->addOption('saison', null, InputOption::VALUE_REQUIRED, 'Libellé de la saison à traiter (défaut : la plus récente)');
    }

    /**
     * Passe quotidienne, à heure ouvrable : un mail du club horodaté à 3 h du matin part en
     * indésirable.
     *
     * Le cron n'a pas de saison sélectionnée — il n'a pas de session, et chaque admin
     * travaille dans la saison de son choix. On prend donc la plus récente, celle où les
     * inscriptions sont en cours, sauf `--saison` explicite.
     *
     * `--dry-run` est là pour la mise en service : on regarde qui partirait avant d'allumer
     * l'interrupteur, une fois. Il court-circuite le service, donc l'interrupteur aussi —
     * c'est voulu, on veut pouvoir inspecter la liste robot éteint.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $libelle = $input->getOption('saison');
        $season = \is_string($libelle)
            ? $this->seasonRepository->findOneBy(['label' => $libelle])
            : $this->seasonRepository->findMostRecent();

        if ($season === null) {
            $io->error(\is_string($libelle) ? sprintf('Saison « %s » introuvable.', $libelle) : 'Aucune saison en base.');

            return Command::FAILURE;
        }

        $settings = $this->clubSettings->get();

        if ($input->getOption('dry-run')) {
            return $this->afficherLaListe($io, $this->resolver->dues($season), $season->getLabel());
        }

        if (!$settings->isRelanceActive()) {
            $io->note('Relance automatique désactivée (réglages du club) : aucun mail envoyé.');

            return Command::SUCCESS;
        }

        $resultat = $this->relanceService->envoyerLesDues($season);

        if ($resultat->envoyes === 0 && $resultat->echecs === 0) {
            $io->success(sprintf('Saison %s : personne à relancer aujourd\'hui.', $season->getLabel()));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Saison %s : %d relance(s) envoyée(s), %d échec(s).',
            $season->getLabel(),
            $resultat->envoyes,
            $resultat->echecs,
        ));

        return $resultat->echecs > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param RelanceDue[] $dues */
    private function afficherLaListe(SymfonyStyle $io, array $dues, string $saison): int
    {
        if ($dues === []) {
            $io->success(sprintf('Saison %s : personne à relancer aujourd\'hui.', $saison));

            return Command::SUCCESS;
        }

        $io->table(
            ['Licencié', 'Équipe', 'Étape', 'Dernier contact', 'Relance n°'],
            array_map(static fn (RelanceDue $due): array => [
                $due->nomPrenom(),
                $due->equipe(),
                $due->etape->label(),
                $due->dernierContact->resume(),
                (string) $due->numero,
            ], $dues),
        );

        $io->note(sprintf('%d relance(s) partiraient. Aucun mail envoyé (--dry-run).', count($dues)));

        return Command::SUCCESS;
    }
}
