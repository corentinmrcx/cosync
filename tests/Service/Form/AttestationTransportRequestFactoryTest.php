<?php declare(strict_types=1);

namespace App\Tests\Service\Form;

use App\Service\Inscription\AttestationTransportRequestFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validation du parsing de l'attestation de transport, en particulier le cas
 * « véhicule neuf » qui rend la date de contrôle technique facultative.
 */
final class AttestationTransportRequestFactoryTest extends TestCase
{
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgo=';

    private AttestationTransportRequestFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new AttestationTransportRequestFactory();
    }

    public function testVehiculeNeufRendLaDateFacultative(): void
    {
        $data = $this->factory->fromRequest($this->buildRequest([
            'attestation_vehicule_neuf' => '1',
            'attestation_date_ct' => '',
        ]));

        self::assertNotNull($data);
        self::assertTrue($data->vehiculeNeuf);
        self::assertNull($data->dateCT);
    }

    public function testDateRenseigneeSansVehiculeNeuf(): void
    {
        $data = $this->factory->fromRequest($this->buildRequest([
            'attestation_date_ct' => '2024-03-15',
        ]));

        self::assertNotNull($data);
        self::assertFalse($data->vehiculeNeuf);
        self::assertSame('2024-03-15', $data->dateCT?->format('Y-m-d'));
    }

    public function testDateManquanteSansVehiculeNeufEstRejetee(): void
    {
        $data = $this->factory->fromRequest($this->buildRequest([
            'attestation_date_ct' => '',
        ]));

        self::assertNull($data);
    }

    public function testDateDansLeFuturEstRejetee(): void
    {
        $data = $this->factory->fromRequest($this->buildRequest([
            'attestation_date_ct' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
        ]));

        self::assertNull($data);
    }

    public function testChampObligatoireManquantMemeAvecVehiculeNeuf(): void
    {
        $data = $this->factory->fromRequest($this->buildRequest([
            'attestation_vehicule_neuf' => '1',
            'attestation_num_permis' => '',
        ]));

        self::assertNull($data);
    }

    /** @param array<string, string> $overrides */
    private function buildRequest(array $overrides): Request
    {
        $params = array_merge([
            'attestation_nom_conducteur' => 'Martin',
            'attestation_prenom_conducteur' => 'Kevin',
            'attestation_num_permis' => '123456789',
            'attestation_assurance' => 'Macif — 12 rue de la Paix',
            'attestation_date_ct' => '2024-03-15',
            'attestation_signature_data' => self::SIGNATURE,
            'attestation_engagement' => '1',
        ], $overrides);

        return new Request(request: $params);
    }
}
