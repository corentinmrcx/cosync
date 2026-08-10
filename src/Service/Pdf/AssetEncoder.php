<?php declare(strict_types=1);

namespace App\Service\Pdf;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Embarque les images des PDF en base64.
 *
 * Un logo absent renvoie null plutôt que de faire échouer la génération : mieux vaut une
 * attestation sans en-tête qu'une signature perdue faute d'avoir pu produire le document.
 */
final class AssetEncoder
{
    private const LOGO_CLUB = 'logo/logo.png';
    private const LOGO_FOYER = 'logo/foyerDeSoudron.png';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function logoClub(): ?string
    {
        return $this->image(self::LOGO_CLUB);
    }

    public function logoFoyer(): ?string
    {
        return $this->image(self::LOGO_FOYER);
    }

    /** @param string $cheminRelatif chemin sous public/images/ */
    public function image(string $cheminRelatif): ?string
    {
        $chemin = $this->projectDir . '/public/images/' . $cheminRelatif;

        if (!is_file($chemin)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($chemin));
    }
}
