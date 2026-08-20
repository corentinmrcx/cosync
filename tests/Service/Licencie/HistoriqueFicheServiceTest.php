<?php declare(strict_types=1);

namespace App\Tests\Service\Licencie;

use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\Transaction;
use App\Enum\PaymentMode;
use App\Repository\AttestationCleRepository;
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

    private function service(): HistoriqueFicheService
    {
        return new HistoriqueFicheService(
            $this->inerte(DocumentRequirementResolver::class),
            $this->inerte(DetenteurEffectifResolver::class),
            $this->inerte(AttestationCleRepository::class),
        );
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
        $licencie->setLinkSentAt(new \DateTimeImmutable('2026-08-16 16:35:00'));

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setFormCompletedAt(new \DateTimeImmutable('2026-08-16 17:38:16'));
        $licencie->setDossierClub($dossier);

        // Encaissé le 16, saisi à 17:38:30 — soit après la soumission du formulaire.
        $paiement = $this->transaction('2026-08-16', '2026-08-16 17:38:30');

        $labels = array_map(
            static fn ($e): string => $e->label,
            $this->service()->pourLicencie($licencie, [$paiement]),
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
        $licencie->setLinkSentAt(new \DateTimeImmutable('2026-08-16 16:35:00'));

        $evenements = $this->service()->pourLicencie($licencie, []);

        self::assertSame($evenements[1]->date, $evenements[1]->triDate);
    }
}
