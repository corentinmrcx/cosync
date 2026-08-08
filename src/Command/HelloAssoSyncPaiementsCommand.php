<?php declare(strict_types=1);

namespace App\Command;

use App\Repository\DossierClubRepository;
use App\Service\Payment\HelloAssoException;
use App\Service\Payment\HelloAssoPaymentRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:helloasso:sync-paiements',
    description: 'Revérifie auprès de HelloAsso les intentions de paiement en attente et enregistre les encaissements manquants',
)]
final class HelloAssoSyncPaiementsCommand extends Command
{
    public function __construct(
        private readonly DossierClubRepository $dossierRepository,
        private readonly HelloAssoPaymentRecorder $recorder,
    ) {
        parent::__construct();
    }

    /**
     * Filet de sécurité derrière le webhook : si une notification n'est jamais arrivée
     * (URL mal configurée, application indisponible), l'encaissement est rattrapé ici.
     * Le recorder étant idempotent, la commande peut tourner aussi souvent que voulu.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dossiers = $this->dossierRepository->findWithPendingHelloAssoPayment();

        if ($dossiers === []) {
            $io->success('Aucune intention de paiement en attente.');

            return Command::SUCCESS;
        }

        $enregistres = 0;
        $echecs = 0;

        foreach ($dossiers as $dossier) {
            $intentId = $dossier->getHelloassoCheckoutIntentId();
            if ($intentId === null) {
                continue;
            }

            try {
                if ($this->recorder->recordFromCheckoutIntent($intentId)) {
                    ++$enregistres;
                    $io->writeln(sprintf('Paiement enregistré pour %s', $dossier->getLicencie()->getNomPrenom()));
                }
            } catch (HelloAssoException $e) {
                ++$echecs;
                $io->warning(sprintf('Intention %s : %s', $intentId, $e->getMessage()));
            }
        }

        $io->success(sprintf(
            '%d intention(s) vérifiée(s), %d paiement(s) enregistré(s), %d échec(s).',
            count($dossiers),
            $enregistres,
            $echecs,
        ));

        return $echecs > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
