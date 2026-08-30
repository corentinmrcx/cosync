<?php declare(strict_types=1);

namespace App\Tests\Service\Licencie;

use App\Entity\DossierClub;
use App\Entity\EnvoiMail;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Enum\OrigineEnvoi;
use App\Enum\PaymentMode;
use App\Enum\TypeMail;
use App\Repository\AttestationCleRepository;
use App\Repository\EnvoiMailRepository;
use App\Service\Cle\DetenteurEffectifResolver;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Licencie\HistoriqueFicheService;
use PHPUnit\Framework\TestCase;

/**
 * La chronologie d'une fiche mélange deux natures de dates, et c'est là qu'elle s'est
 * trompée : les événements du parcours sont horodatés à la seconde, un paiement porte la
 * date **métier** de l'encaissement — celle du chèque, sans heure. Triée sur cette date,
 * elle valait minuit et faisait remonter le paiement avant l'envoi du lien et avant le
 * formulaire qui l'avait pourtant déclenché.
 *
 * Les lignes de mail viennent désormais du journal des envois et non plus de `linkSentAt`,
 * qui n'en gardait que la dernière : un renvoi doit se voir.
 */
final class HistoriqueFicheServiceTest extends TestCase
{
    /**
     * Les trois collaborateurs ne servent qu'à `pourDirigeant()` — et sont `final`, donc
     * non doublables. On les instancie sans constructeur : le scénario licencié ne les
     * appelle jamais, un appel accidentel casserait donc franchement le test.
     *
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function inerte(string $classe): object
    {
        return (new \ReflectionClass($classe))->newInstanceWithoutConstructor();
    }

    /** @param EnvoiMail[] $envois */
    private function service(array $envois = []): HistoriqueFicheService
    {
        $envoiRepo = $this->createStub(EnvoiMailRepository::class);
        $envoiRepo->method('pourLicencie')->willReturn($envois);

        return new HistoriqueFicheService(
            $this->inerte(DocumentRequirementResolver::class),
            $this->inerte(DetenteurEffectifResolver::class),
            $this->inerte(AttestationCleRepository::class),
            $envoiRepo,
        );
    }

    private function envoi(TypeMail $type, string $sentAt): EnvoiMail
    {
        return (new EnvoiMail($type, OrigineEnvoi::ADMIN, 'parent@example.test'))
            ->setSentAt(new \DateTimeImmutable($sentAt));
    }

    /** `importedAt` est fixé au constructeur : seule la réflexion permet de le dater. */
    private function licencie(string $importeLe): Licencie
    {
        $licencie = (new Licencie())->setNom('KADRI')->setPrenom('Yascine');

        $prop = new \ReflectionProperty(Licencie::class, 'importedAt');
        $prop->setValue($licencie, new \DateTimeImmutable($importeLe));

        return $licencie;
    }

    private function transaction(string $datePaiement, string $createdAt): Transaction
    {
        $transaction = (new Transaction())
            ->setMode(PaymentMode::CB_ONLINE)
            ->setMontant('120.00')
            ->setSeason(new Season())
            ->setDatePaiement(new \DateTimeImmutable($datePaiement));

        $prop = new \ReflectionProperty(Transaction::class, 'createdAt');
        $prop->setValue($transaction, new \DateTimeImmutable($createdAt));

        return $transaction;
    }

    public function testLePaiementSeRangeAApresLeFormulaireQuiLADeclenche(): void
    {
        $licencie = $this->licencie('2026-08-15 17:32:00');
        $lien = $this->envoi(TypeMail::INSCRIPTION_LINK, '2026-08-16 16:35:00');

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setFormCompletedAt(new \DateTimeImmutable('2026-08-16 17:38:16'));
        $licencie->setDossierClub($dossier);

        // Encaissé le 16, saisi à 17:38:30 — soit après la soumission du formulaire.
        $paiement = $this->transaction('2026-08-16', '2026-08-16 17:38:30');

        $labels = array_map(
            static fn ($e): string => $e->label,
            $this->service([$lien])->pourLicencie($licencie, [$paiement]),
        );

        self::assertSame([
            'Licencié importé depuis FootClubs',
            'Lien d\'inscription envoyé par email',
            'Formulaire complété par le licencié',
            'Paiement enregistré — Carte bancaire 120.00 €',
        ], $labels);
    }

    /**
     * Le tri suit l'heure de saisie, mais la ligne continue d'annoncer la date de
     * l'encaissement : un chèque daté du 12 saisi le 18 reste « payé le 12 ».
     */
    public function testLaDateAfficheeResteCelleDuPaiement(): void
    {
        $licencie = $this->licencie('2026-08-01 09:00:00');
        $paiement = $this->transaction('2026-08-12', '2026-08-18 10:05:00');

        $evenements = $this->service()->pourLicencie($licencie, [$paiement]);
        $ligne = end($evenements);

        self::assertSame('2026-08-12', $ligne->date->format('Y-m-d'));
        self::assertSame('2026-08-18 10:05:00', $ligne->triDate->format('Y-m-d H:i:s'));
        self::assertSame('d/m/Y', $ligne->format);
    }

    /** Sans horodatage distinct, un événement se trie sur sa propre date — cas de tous les autres. */
    public function testUnEvenementHorodateSeTrieSurSaDate(): void
    {
        $licencie = $this->licencie('2026-08-15 17:32:00');
        $lien = $this->envoi(TypeMail::INSCRIPTION_LINK, '2026-08-16 16:35:00');

        $evenements = $this->service([$lien])->pourLicencie($licencie, []);

        self::assertSame($evenements[1]->date, $evenements[1]->triDate);
    }

    /**
     * La raison d'être du journal : `linkSentAt` étant écrasé à chaque renvoi, une relance
     * ne laissait aucune trace — et l'admin pouvait réécrire à quelqu'un que le club venait
     * de relancer. Chaque envoi doit désormais tenir sa ligne.
     */
    public function testChaqueRenvoiDeLienTientSaPropreLigne(): void
    {
        $licencie = $this->licencie('2026-08-01 09:00:00');

        $evenements = $this->service([
            $this->envoi(TypeMail::INSCRIPTION_LINK, '2026-08-05 10:00:00'),
            $this->envoi(TypeMail::INSCRIPTION_LINK, '2026-08-20 09:15:00'),
        ])->pourLicencie($licencie, []);

        self::assertCount(3, $evenements);
        self::assertSame('2026-08-05', $evenements[1]->date->format('Y-m-d'));
        self::assertSame('2026-08-20', $evenements[2]->date->format('Y-m-d'));
    }

    /** L'auteur affiché retombe sur l'origine quand aucun admin n'est à la manœuvre. */
    public function testUnEnvoiAutomatiqueEstAttribueAuSysteme(): void
    {
        $licencie = $this->licencie('2026-08-01 09:00:00');

        $validation = (new EnvoiMail(TypeMail::VALIDATION, OrigineEnvoi::AUTOMATIQUE, 'parent@example.test'))
            ->setSentAt(new \DateTimeImmutable('2026-08-22 08:00:00'));

        $evenements = $this->service([$validation])->pourLicencie($licencie, []);

        self::assertSame('Confirmation de licence validée envoyée', $evenements[1]->label);
        self::assertSame('Système', $evenements[1]->who);
    }
}
