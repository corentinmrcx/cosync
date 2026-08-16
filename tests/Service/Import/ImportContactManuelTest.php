<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\DTO\ContactData;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\ChampContact;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use App\Service\Dirigeant\DirigeantService;
use App\Service\Import\ImportService;
use App\Service\Licencie\LicencieService;

/**
 * Coordonnées corrigées à la main : elles font autorité sur l'export FootClubs.
 *
 * Une adresse fausse dans FootClubs ne peut pas toujours y être corrigée le jour même (dossier
 * en cours de validation à la ligue). Sans verrou, l'admin corrigeait dans CoSync et l'import
 * suivant ramenait la faute en silence — le lien d'inscription repartait dans le vide.
 */
final class ImportContactManuelTest extends ImportIntegrationTestCase
{
    private const HEADERS = [
        'Nom', 'Prénom', 'Numéro personne', 'Sous-catégorie', 'Type',
        'Date de naissance', 'Email', 'Téléphone mobile', 'Adresse 1', 'Code postal', 'Ville',
    ];

    private function seed(): Season
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->em->flush();

        return $season;
    }

    /** @param list<list<string>> $rows */
    private function importer(Season $season, array $rows): void
    {
        $this->service(ImportService::class)->importFromXlsx($this->makeXlsx(self::HEADERS, $rows), $season);
    }

    /** @return list<string> */
    private function ligneJoueur(string $email, string $telephone = '0670290965'): array
    {
        return ['MARTIN', 'Kevin', '111', 'Libre / Senior', 'Joueur', '12/04/2003', $email, $telephone, '', '', ''];
    }

    /** @return list<string> */
    private function ligneDirigeant(string $email): array
    {
        return ['DUPONT', 'Claire', '222', 'Dirigeante', 'Dirigeant', '05/09/1980', $email, '0611223344', '', '', ''];
    }

    private function licencie(Season $season): Licencie
    {
        /** @var LicencieRepository $repo */
        $repo = $this->service(LicencieRepository::class);
        $licencie = $repo->findByNumLicence('111', $season);
        self::assertNotNull($licencie);

        return $licencie;
    }

    private function dirigeant(Season $season): Dirigeant
    {
        /** @var DirigeantRepository $repo */
        $repo = $this->service(DirigeantRepository::class);
        $dirigeant = $repo->findByNumLicence('222', $season);
        self::assertNotNull($dirigeant);

        return $dirigeant;
    }

    private function corriger(Licencie $licencie, ?string $email, ?string $telephone = null): void
    {
        $data = new ContactData();
        $data->email = $email;
        $data->telephone = $telephone ?? $licencie->getTelephone();

        $this->service(LicencieService::class)->editContact($licencie, $data);
    }

    public function testUnEmailCorrigeALaMainSurvitAuxImportsSuivants(): void
    {
        $season = $this->seed();
        $this->importer($season, [$this->ligneJoueur('faute.de.frappe@example.fr')]);

        $this->corriger($this->licencie($season), 'kevin@example.fr');

        $this->importer($season, [$this->ligneJoueur('faute.de.frappe@example.fr')]);

        self::assertSame('kevin@example.fr', $this->licencie($season)->getEmail());
        self::assertTrue($this->licencie($season)->isEmailManuel());
    }

    public function testLeVerrouNeConcerneQueLeChampCorrige(): void
    {
        $season = $this->seed();
        $this->importer($season, [$this->ligneJoueur('faute.de.frappe@example.fr', '0670290965')]);

        $this->corriger($this->licencie($season), 'kevin@example.fr');

        // Le téléphone n'a pas été touché : l'export reste sa source de vérité.
        $this->importer($season, [$this->ligneJoueur('faute.de.frappe@example.fr', '0699887766')]);

        $licencie = $this->licencie($season);
        self::assertSame('kevin@example.fr', $licencie->getEmail());
        self::assertSame('+33699887766', $licencie->getTelephone());
        self::assertFalse($licencie->isTelephoneManuel());
    }

    public function testReprendreImportRendLaMainAFootClubs(): void
    {
        $season = $this->seed();
        $this->importer($season, [$this->ligneJoueur('faute.de.frappe@example.fr')]);
        $this->corriger($this->licencie($season), 'kevin@example.fr');

        $this->service(LicencieService::class)->reprendreImport($this->licencie($season), ChampContact::EMAIL);

        // FootClubs corrigé de son côté : la valeur de l'export repasse devant.
        $this->importer($season, [$this->ligneJoueur('kevin.martin@example.fr')]);

        self::assertSame('kevin.martin@example.fr', $this->licencie($season)->getEmail());
        self::assertFalse($this->licencie($season)->isEmailManuel());
    }

    public function testUneSaisieIdentiqueNePoseAucunVerrou(): void
    {
        $season = $this->seed();
        $this->importer($season, [$this->ligneJoueur('kevin@example.fr')]);

        // L'admin ouvre l'écran et enregistre sans rien changer.
        $this->corriger($this->licencie($season), 'kevin@example.fr');

        $this->importer($season, [$this->ligneJoueur('nouvelle.adresse@example.fr')]);

        self::assertSame('nouvelle.adresse@example.fr', $this->licencie($season)->getEmail());
        self::assertFalse($this->licencie($season)->isEmailManuel());
    }

    public function testLeVerrouProtegeAussiLesDirigeants(): void
    {
        $season = $this->seed();
        $this->importer($season, [$this->ligneDirigeant('faute.de.frappe@example.fr')]);

        $dirigeant = $this->dirigeant($season);
        $dirigeant->setEmail('claire@example.fr');
        $dirigeant->setEmailManuel(true);
        $this->em->flush();

        $this->importer($season, [$this->ligneDirigeant('faute.de.frappe@example.fr')]);

        self::assertSame('claire@example.fr', $this->dirigeant($season)->getEmail());

        $this->service(DirigeantService::class)->reprendreImport($this->dirigeant($season), ChampContact::EMAIL);
        $this->importer($season, [$this->ligneDirigeant('claire.dupont@example.fr')]);

        self::assertSame('claire.dupont@example.fr', $this->dirigeant($season)->getEmail());
    }
}
