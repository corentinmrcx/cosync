<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationGroupeReglagesData;
use App\DTO\DotationLigneReglagesData;
use App\Enum\DotationEligibilite;
use Symfony\Component\HttpFoundation\Request;

/**
 * Transforme le POST « réglages d'un groupe de choix » en DTO typé.
 *
 * Le formulaire poste `reglages[<ligneId>][...]`. Une case à cocher décochée n'étant pas postée,
 * chaque option porte un `_present` : sans lui, décocher « texte à personnaliser » sur la
 * dernière option d'un groupe serait indiscernable d'une option absente du formulaire.
 */
final class DotationGroupeReglagesFactory
{
    public function fromRequest(Request $request): DotationGroupeReglagesData
    {
        $brut = (array) ($request->request->all()['reglages'] ?? []);
        $parLigne = [];

        foreach ($brut as $ligneId => $champs) {
            if (!is_array($champs) || ($champs['_present'] ?? null) !== '1') {
                continue;
            }

            $max = trim((string) ($champs['personnalisation_max'] ?? ''));

            $parLigne[(int) $ligneId] = new DotationLigneReglagesData(
                DotationEligibilite::tryFrom((string) ($champs['eligibilite'] ?? '')) ?? DotationEligibilite::TOUS,
                ($champs['personnalisation_requise'] ?? null) === '1',
                isset($champs['personnalisation_label']) ? (string) $champs['personnalisation_label'] : null,
                $max !== '' ? (int) $max : null,
            );
        }

        return new DotationGroupeReglagesData($parLigne);
    }
}
