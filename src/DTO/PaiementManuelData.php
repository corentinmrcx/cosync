<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\PaymentMode;
use Symfony\Component\HttpFoundation\Request;

final class PaiementManuelData
{
    public function __construct(
        public readonly PaymentMode $mode,
        public readonly float $montant,
        public readonly \DateTimeImmutable $datePaiement,
        public readonly ?string $reference,
        public readonly ?string $note,
    ) {}

    /** @throws \DomainException si le mode, le montant ou la date sont inexploitables */
    public static function fromRequest(Request $request): self
    {
        $mode = PaymentMode::tryFrom((string) $request->request->get('mode', ''));
        $montant = (float) str_replace(',', '.', (string) $request->request->get('montant', '0'));
        $dateBrute = (string) $request->request->get('date_paiement', '');

        if ($mode === null || $montant <= 0 || $dateBrute === '') {
            throw new \DomainException('Mode, montant ou date invalide.');
        }

        try {
            $date = new \DateTimeImmutable($dateBrute);
        } catch (\Exception) {
            throw new \DomainException('Date invalide.');
        }

        return new self(
            $mode,
            $montant,
            $date,
            $request->request->get('reference') ?: null,
            $request->request->get('note') ?: null,
        );
    }
}
