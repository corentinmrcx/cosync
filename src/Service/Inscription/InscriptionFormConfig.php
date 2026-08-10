<?php declare(strict_types=1);

namespace App\Service\Inscription;

use App\Entity\DocumentSignable;
use App\Entity\Licencie;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Dotation\DotationModeleService;
use App\Service\Dotation\DotationResolver;
use App\Service\Payment\CotisationResolver;

/**
 * Ce que le composant Alpine du formulaire public a besoin de savoir : le nombre
 * d'étapes, le montant dû, et quels textes de flocage réclamer selon les choix faits.
 *
 * Construit ici plutôt que dans la vue : c'est une transformation de collections, pas
 * de l'affichage.
 */
final class InscriptionFormConfig
{
    public function __construct(
        private readonly DotationResolver $dotationResolver,
        private readonly CotisationResolver $cotisationResolver,
        private readonly DocumentRequirementResolver $documentResolver,
    ) {}

    /** @return array<string, mixed> passé tel quel à inscriptionForm() */
    public function pour(Licencie $licencie, ?string $urlDemo = null): array
    {
        $groupes = $this->dotationResolver->getChoiceGroups($licencie);

        $config = [
            'isJeune' => $licencie->getCategory()->isJeune(),
            'montant' => $this->cotisationResolver->resolve($licencie),
            'documents' => array_map(
                static fn (DocumentSignable $document): array => ['id' => $document->getId()],
                $this->documentResolver->manquantsPourLicencie($licencie),
            ),
            'dotationGroupes' => array_column($groupes, 'groupe'),
            'dotationFlocages' => $this->flocagesParGroupe($groupes),
            'dotationAutoFlocages' => $this->flocagesImposes($licencie),
        ];

        if ($urlDemo !== null) {
            $config['demo'] = true;
            $config['demoUrl'] = $urlDemo;
        }

        return $config;
    }

    /**
     * Variante pour l'écran de démonstration : le licencié n'existe pas en base, aucun
     * kit ne peut lui être résolu, mais les documents réels de la saison sont affichés.
     *
     * @param DocumentSignable[] $documents
     *
     * @return array<string, mixed>
     */
    public function pourDemo(Licencie $licencie, array $documents, string $urlDemo): array
    {
        return [
            'isJeune' => $licencie->getCategory()->isJeune(),
            'montant' => $licencie->getSeason()->getCotisationDefaut(),
            'documents' => array_map(
                static fn (DocumentSignable $document): array => ['id' => $document->getId()],
                $documents,
            ),
            'dotationGroupes' => [],
            'dotationFlocages' => [],
            'dotationAutoFlocages' => [],
            'demo' => true,
            'demoUrl' => $urlDemo,
        ];
    }

    /**
     * Options réclamant un texte, groupées par choix.
     *
     * Une liste, jamais une table indexée par identifiant d'article : côté JavaScript
     * l'ordre compte, et le nom de l'article sert au récapitulatif — avec deux flocages,
     * deux mots en capitales ne se distinguent pas l'un de l'autre.
     *
     * @param array<int, array{groupe: string, options: array<int, \App\Entity\DotationModeleLigne>}> $groupes
     *
     * @return array<string, list<array{id: int, max: int, article: string}>>
     */
    private function flocagesParGroupe(array $groupes): array
    {
        $parGroupe = [];

        foreach ($groupes as $groupe) {
            $options = [];

            foreach ($groupe['options'] as $option) {
                if (!$option->isPersonnalisationRequise()) {
                    continue;
                }

                $options[] = [
                    'id' => (int) $option->getStockItem()->getId(),
                    'max' => $option->getPersonnalisationMaxLength() ?? DotationModeleService::PERSONNALISATION_MAX_DEFAUT,
                    'article' => $option->getStockItem()->getNom(),
                ];
            }

            if ($options !== []) {
                $parGroupe[$groupe['groupe']] = $options;
            }
        }

        return $parGroupe;
    }

    /**
     * Textes dus sans qu'aucune question ne soit posée : groupe réduit à une option
     * pour ce licencié, ou article fixe personnalisé.
     *
     * @return list<array{cle: string, max: int, article: string}>
     */
    private function flocagesImposes(Licencie $licencie): array
    {
        return array_map(
            static fn (array $demande): array => [
                'cle' => $demande['cle'],
                'max' => $demande['ligne']->getPersonnalisationMaxLength() ?? DotationModeleService::PERSONNALISATION_MAX_DEFAUT,
                'article' => $demande['ligne']->getStockItem()->getNom(),
            ],
            $this->dotationResolver->getAutoPersonnalisationRequests($licencie),
        );
    }
}
