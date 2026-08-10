<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationChoixData;
use App\Entity\DotationModeleLigne;
use App\Entity\Licencie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Construit et valide la partie « dotation » du formulaire public : l'option retenue dans
 * chaque groupe de choix, et le texte de personnalisation quand l'option l'exige.
 *
 * Retourne null dès qu'une réponse manque, sort de son groupe, n'est pas éligible au
 * licencié, ou qu'un texte est invalide — le contrôleur rejette alors la soumission en bloc,
 * comme pour les autres champs obligatoires.
 */
final class DotationChoixRequestFactory
{
    /** Ce qu'un floqueur sait imprimer : lettres accentuées, chiffres, espace, apostrophe, tiret, point. */
    private const FLOCAGE_PATTERN = '/^[\p{L}\p{N} .\'\-]+$/u';

    public function __construct(
        private readonly DotationResolver $resolver,
        private readonly DotationModeleService $modeleService,
    ) {}

    public function fromRequest(Request $request, Licencie $licencie): ?DotationChoixData
    {
        $rawChoix = (array) ($request->request->all()['dotation_choix'] ?? []);

        // 1. Une option par groupe de choix, prise parmi les options réellement proposées.
        $choix = [];
        foreach ($this->resolver->getChoiceGroups($licencie) as $groupe) {
            $voulu = (int) ($rawChoix[$groupe['groupe']] ?? 0);
            if ($voulu <= 0) {
                return null;
            }

            $idsProposes = array_map(
                static fn (DotationModeleLigne $l): int => $l->getStockItem()->getId(),
                $groupe['options'],
            );
            // Sans ce contrôle, n'importe quel identifiant posté serait accepté et stocké.
            if (!in_array($voulu, $idsProposes, true)) {
                return null;
            }

            $choix[$groupe['groupe']] = $voulu;
        }

        // 2. Un texte par option retenue qui en réclame un — y compris quand aucune question
        //    de choix n'a été posée (groupe auto-résolu, ou article fixe personnalisé).
        $rawTextes = (array) ($request->request->all()['dotation_personnalisation'] ?? []);
        $textes = [];
        foreach ($this->resolver->getPersonnalisationRequests($licencie, $choix) as $demande) {
            $texte = $this->normalise($rawTextes[$demande['cle']] ?? null);
            if ($texte === null) {
                return null;
            }
            if (mb_strlen($texte) > $this->modeleService->maxLengthFor($demande['ligne'])) {
                return null;
            }
            if (preg_match(self::FLOCAGE_PATTERN, $texte) !== 1) {
                return null;
            }

            $textes[$demande['cle']] = $texte;
        }

        // 3. Confirmation de l'orthographe : sans contrôle serveur, la case ne serait qu'un
        //    ornement. Un vêtement floqué avec une faute est irrattrapable.
        if ($textes !== [] && $request->request->get('flocage_confirme') !== '1') {
            return null;
        }

        return new DotationChoixData($choix, $textes);
    }

    /** Trim + espaces internes compactés. Null si la valeur est absente ou vide. */
    private function normalise(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $texte = trim((string) preg_replace('/\s+/u', ' ', $raw));

        return $texte !== '' ? $texte : null;
    }
}
