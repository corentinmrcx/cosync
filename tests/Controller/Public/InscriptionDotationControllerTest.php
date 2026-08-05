<?php declare(strict_types=1);

namespace App\Tests\Controller\Public;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\DotationEligibilite;
use App\Enum\LicenceStatus;
use App\Enum\NatureLicence;
use App\Enum\StockItemVetementType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours public du choix de dotation et du texte de flocage.
 *
 * Kit de la saison : « Dotation » = veste (tout le monde) ou t-shirt floqué (renouvellements).
 * Un nouveau licencié ne doit voir aucune question ; un renouvellement choisit, et s'il prend
 * le t-shirt il doit saisir un texte et en confirmer l'orthographe.
 */
final class InscriptionDotationControllerTest extends WebTestCase
{
    private const MAX_FLOCAGE = 15;

    public function testRenouvellementVoitLesDeuxOptionsEtLeChampDeFlocage(): void
    {
        $client = static::createClient();
        ['uuid' => $uuid] = $this->seed(NatureLicence::RENOUVELLEMENT);

        $client->request('GET', '/inscription/' . $uuid);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Veste', $html);
        self::assertStringContainsString('T-shirt', $html);
        self::assertStringContainsString('Nom à floquer au dos', $html);
        self::assertStringContainsString('flocage_confirme', $html);
    }

    public function testNouveauLicencieNeVoitAucuneQuestionDeDotation(): void
    {
        $client = static::createClient();
        ['uuid' => $uuid] = $this->seed(NatureLicence::NOUVELLE_DEMANDE);

        $client->request('GET', '/inscription/' . $uuid);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('dotation_choix', $html, 'Aucun choix ne lui est proposé.');
        self::assertStringNotContainsString('T-shirt', $html);
    }

    public function testSoumissionEnregistreLeChoixEtLeTexteDeFlocage(): void
    {
        $client = static::createClient();
        ['uuid' => $uuid, 'tshirt' => $tshirtId] = $this->seed(NatureLicence::RENOUVELLEMENT);

        $client->request('POST', '/inscription/' . $uuid, $this->payload($client, $uuid, $tshirtId, [
            'dotation_personnalisation' => ['Dotation' => 'Coco'],
            'flocage_confirme'          => '1',
        ]));

        self::assertResponseRedirects();

        $dossier = $this->reloadDossier($uuid);
        self::assertNotNull($dossier->getFormCompletedAt(), 'Le formulaire doit être enregistré.');
        self::assertSame(['Dotation' => $tshirtId], $dossier->getDotationChoix());
        self::assertSame(['Dotation' => 'Coco'], $dossier->getDotationPersonnalisation());
    }

    public function testTexteManquantRejetteLaSoumission(): void
    {
        $client = static::createClient();
        ['uuid' => $uuid, 'tshirt' => $tshirtId] = $this->seed(NatureLicence::RENOUVELLEMENT);

        $client->request('POST', '/inscription/' . $uuid, $this->payload($client, $uuid, $tshirtId, [
            'flocage_confirme' => '1',
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid);
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt(), 'Rien ne doit être enregistré.');
    }

    public function testConfirmationDOrthographeManquanteRejetteLaSoumission(): void
    {
        $client = static::createClient();
        ['uuid' => $uuid, 'tshirt' => $tshirtId] = $this->seed(NatureLicence::RENOUVELLEMENT);

        $client->request('POST', '/inscription/' . $uuid, $this->payload($client, $uuid, $tshirtId, [
            'dotation_personnalisation' => ['Dotation' => 'Coco'],
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid);
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    public function testChoixDUnArticleHorsDuGroupeEstRejete(): void
    {
        $client = static::createClient();
        ['uuid' => $uuid, 'etranger' => $etrangerId] = $this->seed(NatureLicence::RENOUVELLEMENT);

        $client->request('POST', '/inscription/' . $uuid, $this->payload($client, $uuid, $etrangerId, [
            'dotation_personnalisation' => ['Dotation' => 'Coco'],
            'flocage_confirme'          => '1',
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid);
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    public function testTexteTropLongRejetteLaSoumission(): void
    {
        $client = static::createClient();
        ['uuid' => $uuid, 'tshirt' => $tshirtId] = $this->seed(NatureLicence::RENOUVELLEMENT);

        $client->request('POST', '/inscription/' . $uuid, $this->payload($client, $uuid, $tshirtId, [
            'dotation_personnalisation' => ['Dotation' => str_repeat('A', self::MAX_FLOCAGE + 1)],
            'flocage_confirme'          => '1',
        ]));

        self::assertResponseRedirects('/inscription/' . $uuid);
        self::assertNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    public function testNouveauLicencieSoumetSansPosterDeChoix(): void
    {
        $client = static::createClient();
        ['uuid' => $uuid] = $this->seed(NatureLicence::NOUVELLE_DEMANDE);

        $payload = $this->payload($client, $uuid, null, []);
        unset($payload['dotation_choix']);

        $client->request('POST', '/inscription/' . $uuid, $payload);

        self::assertResponseRedirects();
        self::assertNotNull($this->reloadDossier($uuid)->getFormCompletedAt());
    }

    /* ── Fixtures ── */

    /**
     * Champs obligatoires du formulaire d'un sénior, plus les clés propres au test.
     * Le jeton CSRF est repris de la page réelle : sans lui, toute soumission serait rejetée
     * pour la mauvaise raison et les tests de rejet ne prouveraient rien.
     *
     * @param  array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(KernelBrowser $client, string $uuid, ?int $choixId, array $extra): array
    {
        $crawler = $client->request('GET', '/inscription/' . $uuid);
        $token   = $crawler->filter('input[name="_token"]')->attr('value');

        return array_merge([
            '_token'             => $token,
            'taille_haut'        => 'L',
            'taille_bas'         => 'M',
            'pointure'           => '42',
            'autorisation_photo' => '1',
            'signature_data'     => 'data:image/png;base64,iVBORw0KGgo=',
            'payment_intention'  => 'especes',
            'dotation_choix'     => ['Dotation' => (string) $choixId],
        ], $extra);
    }

    private function reloadDossier(string $uuid): DossierClub
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Licencie::class, Uuid::fromString($uuid))->getDossierClub();
    }

    /**
     * Saison + kit « Dotation » (veste pour tous / t-shirt floqué pour les renouvellements)
     * + un article hors kit, et un licencié de la nature demandée.
     *
     * @return array{uuid: string, veste: int, tshirt: int, etranger: int}
     */
    private function seed(NatureLicence $nature): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season   = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $veste    = (new StockItem())->setNom('Veste')->setTypeVetement(StockItemVetementType::HAUT);
        $tshirt   = (new StockItem())->setNom('T-shirt')->setTypeVetement(StockItemVetementType::HAUT);
        $etranger = (new StockItem())->setNom('Article hors kit')->setTypeVetement(StockItemVetementType::HAUT);

        $modele = (new DotationModele())->setSeason($season)->setNom('Dotation 2026');

        $ligneVeste = (new DotationModeleLigne())
            ->setStockItem($veste)->setQuantite(1)->setGroupeChoix('Dotation')
            ->setEligibilite(DotationEligibilite::TOUS);
        $ligneTshirt = (new DotationModeleLigne())
            ->setStockItem($tshirt)->setQuantite(1)->setGroupeChoix('Dotation')
            ->setEligibilite(DotationEligibilite::RENOUVELLEMENTS)
            ->setPersonnalisationRequise(true)
            ->setPersonnalisationLabel('Nom à floquer au dos')
            ->setPersonnalisationMaxLength(self::MAX_FLOCAGE);
        $modele->addLigne($ligneVeste);
        $modele->addLigne($ligneTshirt);

        $affectation = (new DotationAffectation())->setSeason($season)->setModele($modele)->setCategory($category);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setCategory($category)
            ->setSeason($season)
            ->setNatureLicence($nature)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus(LicenceStatus::LINK_SENT);

        foreach ([$season, $category, $veste, $tshirt, $etranger, $modele, $ligneVeste, $ligneTshirt, $affectation, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $ids = [
            'uuid'     => (string) $licencie->getUuid(),
            'veste'    => $veste->getId(),
            'tshirt'   => $tshirt->getId(),
            'etranger' => $etranger->getId(),
        ];
        $em->clear();

        return $ids;
    }
}
