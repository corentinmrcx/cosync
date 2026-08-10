<?php declare(strict_types=1);

namespace App\Service\Ops;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BetaModeService
{
    private readonly string $lockFile;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        #[Autowire('%env(DIAG_EMAIL)%')] private readonly string $redirectEmail,
    ) {
        $this->lockFile = $projectDir . '/var/locks/beta_mode.lock';
    }

    public function enable(): void
    {
        // var/ est vidé au déploiement et absent d'un checkout neuf : sans ce mkdir,
        // touch() échoue silencieusement et le mode beta reste inactif — donc les mails
        // partent aux vrais licenciés alors que l'admin croit les avoir coupés.
        $repertoire = \dirname($this->lockFile);

        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException(sprintf('Impossible de créer le répertoire des verrous : %s', $repertoire));
        }

        if (!touch($this->lockFile)) {
            throw new \RuntimeException(sprintf('Impossible d\'activer le mode beta : %s n\'a pas pu être créé.', $this->lockFile));
        }
    }

    public function disable(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    public function isActive(): bool
    {
        return file_exists($this->lockFile);
    }

    public function getRedirectEmail(): string
    {
        return $this->redirectEmail;
    }
}
