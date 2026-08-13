<?php declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Service\Mail\MailerService;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

/**
 * Annonce de la boutique du club, envoyée dans la foulée de l'accusé de réception.
 *
 * Le lien étant un réglage facultatif, le point sensible est le silence : tant qu'aucune
 * boutique n'est configurée, le licencié ne doit recevoir aucun mail — surtout pas un
 * mail dont le bouton pointe dans le vide.
 */
final class BoutiqueMailTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private const URL = 'https://www.helloasso.com/associations/fc-soudron/boutiques/boutique-du-club';

    public function testLeMailPorteLeLienConfigure(): void
    {
        self::bootKernel();
        $this->configurerBoutique(self::URL);

        self::getContainer()->get(MailerService::class)->sendBoutique($this->seedLicencie());

        $messages = $this->messagesEnvoyes();
        self::assertCount(1, $messages);
        self::assertSame('kevin.martin@example.test', $messages[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('boutique', strtolower($messages[0]->getSubject()));
        self::assertStringContainsString(self::URL, (string) $messages[0]->getHtmlBody());
    }

    public function testSansLienConfigureAucunMailNePart(): void
    {
        self::bootKernel();
        $this->configurerBoutique(null);

        self::getContainer()->get(MailerService::class)->sendBoutique($this->seedLicencie());

        self::assertCount(0, $this->messagesEnvoyes());
    }

    public function testUnLicencieSansEmailNeDeclenchePasDEnvoi(): void
    {
        self::bootKernel();
        $this->configurerBoutique(self::URL);

        self::getContainer()->get(MailerService::class)->sendBoutique($this->seedLicencie(email: null));

        self::assertCount(0, $this->messagesEnvoyes());
    }

    /**
     * Le mail de confirmation porte le montant dû et les instructions de paiement : la
     * boutique part à part pour ne pas s'y intercaler.
     */
    public function testLAnnonceEstUnMailDistinctDeLaConfirmation(): void
    {
        self::bootKernel();
        $this->configurerBoutique(self::URL);
        $licencie = $this->seedLicencie();

        $mailer = self::getContainer()->get(MailerService::class);
        $mailer->sendInscriptionConfirmation($licencie, 85);
        $mailer->sendBoutique($licencie);

        $messages = $this->messagesEnvoyes();
        self::assertCount(2, $messages);
        self::assertStringNotContainsString(self::URL, (string) $messages[0]->getHtmlBody());
    }

    /* ── Outils ── */

    private function configurerBoutique(?string $url): void
    {
        $settings = self::getContainer()->get(ClubSettingsService::class);
        $settings->get()->setBoutiqueUrl($url);
        $settings->enregistrer();
    }

    /** @return Email[] */
    private function messagesEnvoyes(): array
    {
        return array_map(static function (\Symfony\Component\Mime\RawMessage $message): Email {
            self::assertInstanceOf(Email::class, $message);

            return $message;
        }, self::getMailerMessages());
    }

    private function seedLicencie(?string $email = 'kevin.martin@example.test'): Licencie
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())->setCode('SENIOR')->setLabel('Sénior')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setEmail($email)
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus(LicenceStatus::FORM_COMPLETED);

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $uuid = $licencie->getUuid();
        $em->clear();

        return $em->find(Licencie::class, $uuid);
    }
}
