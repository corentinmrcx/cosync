<?php declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Entity\DocumentSignature;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Enum\DocumentCible;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fabriques de documents signables pour les tests.
 *
 * Les documents étant désormais des données, presque tous les scénarios commencent
 * par en créer un : centraliser leur construction évite de répéter code, libellé,
 * segments Drive et préfixe de fichier dans chaque test.
 */
final class DocumentFixtures
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param DirigeantRole[] $roles      vide = tous les dirigeants
     * @param Dirigeant[]     $dirigeants désignations individuelles
     */
    public function documentDirigeant(
        Season $season,
        string $code = 'reglement_dirigeant',
        string $titre = 'Règlement intérieur des dirigeants',
        ?string $contenuHtml = '<p>Engagement reserve aux dirigeants du club.</p>',
        array $roles = [],
        array $dirigeants = [],
        bool $actif = true,
        int $sortOrder = 10,
    ): DocumentSignable {
        $document = $this->build($season, $code, $titre, $contenuHtml, DocumentCible::DIRIGEANT, $actif, $sortOrder)
            ->setLibelle('le règlement intérieur des dirigeants du Foyer de Soudron')
            ->setRoles($roles);

        foreach ($dirigeants as $dirigeant) {
            $document->addDirigeant($dirigeant);
        }

        $this->em->persist($document);

        return $document;
    }

    public function documentLicencie(
        Season $season,
        string $code = 'reglement_licencie',
        string $titre = 'Règlement intérieur',
        ?string $contenuHtml = '<p>Engagement reserve aux joueurs du club.</p>',
        bool $actif = true,
        int $sortOrder = 10,
    ): DocumentSignable {
        $document = $this->build($season, $code, $titre, $contenuHtml, DocumentCible::LICENCIE, $actif, $sortOrder)
            ->setLibelle('le règlement intérieur du Foyer de Soudron');

        $this->em->persist($document);

        return $document;
    }

    public function signerParDirigeant(
        DocumentSignable $document,
        Dirigeant $dirigeant,
        string $drivePath = 'drive-file-id',
    ): DocumentSignature {
        $signature = (new DocumentSignature())
            ->setDocument($document)
            ->setDirigeant($dirigeant)
            ->setDrivePath($drivePath);

        $this->em->persist($signature);

        return $signature;
    }

    public function signerParLicencie(
        DocumentSignable $document,
        Licencie $licencie,
        string $drivePath = 'drive-file-id',
    ): DocumentSignature {
        $signature = (new DocumentSignature())
            ->setDocument($document)
            ->setLicencie($licencie)
            ->setDrivePath($drivePath);

        $this->em->persist($signature);

        return $signature;
    }

    private function build(
        Season $season,
        string $code,
        string $titre,
        ?string $contenuHtml,
        DocumentCible $cible,
        bool $actif,
        int $sortOrder,
    ): DocumentSignable {
        return (new DocumentSignable())
            ->setSeason($season)
            ->setCode($code)
            ->setTitre($titre)
            ->setContenuHtml($contenuHtml)
            ->setCible($cible)
            ->setActif($actif)
            ->setSortOrder($sortOrder)
            ->setDriveSegments(['Documents signés', $titre])
            ->setFilePrefix(strtoupper(substr($code, 0, 30)));
    }
}
