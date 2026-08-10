<?php declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\HttpFoundation\Request;

final class FournisseurData
{
    public function __construct(
        public readonly ?string $nom,
        public readonly ?string $contact,
        public readonly ?string $email,
        public readonly bool $actif = true,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::texte($request, 'nom'),
            self::texte($request, 'contact'),
            self::texte($request, 'email'),
            $request->request->get('actif') === '1',
        );
    }

    private static function texte(Request $request, string $champ): ?string
    {
        return trim((string) $request->request->get($champ, '')) ?: null;
    }
}
