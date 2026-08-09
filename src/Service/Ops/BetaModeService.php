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
        touch($this->lockFile);
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
