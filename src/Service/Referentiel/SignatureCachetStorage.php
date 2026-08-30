<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Range la signature scannée du signataire des attestations.
 *
 * Hors de `public/`, délibérément : c'est un paraphe, il ne doit jamais être servi par le
 * serveur web ni finir dans un cache de moteur de recherche. Il n'est lu que par DomPDF,
 * qui l'embarque en base64 dans le document.
 */
final class SignatureCachetStorage
{
    /** Formats acceptés par DomPDF sans conversion préalable. */
    private const EXTENSIONS = ['png', 'jpg', 'jpeg'];

    public function __construct(
        #[Autowire('%app.signature_dir%')] private readonly string $repertoire,
    ) {}

    /**
     * @return string nom du fichier écrit, à conserver dans ClubSettings
     *
     * @throws \InvalidArgumentException si le format n'est pas exploitable par DomPDF
     */
    public function enregistrer(UploadedFile $fichier): string
    {
        $extension = strtolower($fichier->getClientOriginalExtension() ?: (string) $fichier->guessExtension());

        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Format non reconnu : attendu PNG ou JPEG.');
        }

        if (!is_dir($this->repertoire)) {
            mkdir($this->repertoire, 0755, true);
        }

        // Nom horodaté plutôt qu'un nom fixe : un navigateur qui aurait mis l'ancienne
        // image en cache ne doit pas continuer à l'afficher dans l'écran de réglage.
        $nom = sprintf('signature_%s.%s', (new \DateTimeImmutable())->format('YmdHis'), $extension);
        $fichier->move($this->repertoire, $nom);

        return $nom;
    }

    /** Chemin absolu du fichier, ou null s'il a disparu du disque. */
    public function chemin(?string $nomFichier): ?string
    {
        if ($nomFichier === null) {
            return null;
        }

        $chemin = $this->repertoire . '/' . basename($nomFichier);

        return is_file($chemin) ? $chemin : null;
    }

    /** Image embarquée pour DomPDF, ou null : une signature absente n'empêche jamais l'émission. */
    public function dataUrl(?string $nomFichier): ?string
    {
        $chemin = $this->chemin($nomFichier);

        if ($chemin === null) {
            return null;
        }

        $mime = str_ends_with(strtolower($chemin), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($chemin));
    }

    public function supprimer(?string $nomFichier): void
    {
        $chemin = $this->chemin($nomFichier);

        if ($chemin !== null) {
            @unlink($chemin);
        }
    }
}
