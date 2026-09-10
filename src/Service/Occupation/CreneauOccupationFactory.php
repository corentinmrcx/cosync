<?php declare(strict_types=1);

namespace App\Service\Occupation;

use App\DTO\Occupation\CreneauOccupationData;
use App\Enum\JourSemaine;
use App\Enum\UsageOccupation;
use Symfony\Component\HttpFoundation\Request;

/**
 * Construit la saisie d'un créneau depuis la requête de la modale.
 *
 * Une `Request` ne descend jamais jusqu'au service : elle s'arrête ici. Les valeurs
 * inconnues deviennent `null` plutôt que de lever, pour que ce soit
 * {@see CreneauOccupationService} — qui connaît la règle — qui dise ce qui manque, en une
 * phrase compréhensible, plutôt qu'un `ValueError` d'enum.
 */
final class CreneauOccupationFactory
{
    public function depuisRequest(Request $request): CreneauOccupationData
    {
        return new CreneauOccupationData(
            JourSemaine::tryFrom((string) $request->request->get('jour', '')),
            (string) $request->request->get('heureDebut', ''),
            (string) $request->request->get('heureFin', ''),
            $this->entier($request->request->get('equipe')),
            $this->entier($request->request->get('espace')),
            UsageOccupation::tryFrom((string) $request->request->get('usage', '')),
        );
    }

    private function entier(mixed $valeur): ?int
    {
        return is_numeric($valeur) ? (int) $valeur : null;
    }
}
