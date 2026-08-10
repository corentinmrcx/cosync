<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationApercuProfil;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Enum\NatureLicence;

/**
 * Traduit les réglages d'un kit en « voilà ce que chaque profil recevra ».
 *
 * L'éligibilité d'une option est un réglage discret dont la conséquence est invisible : réserver
 * la veste aux nouveaux ne se contente pas de filtrer une liste, cela SUPPRIME la question pour
 * eux — un groupe qui ne garde qu'une option n'est plus un choix, c'est un article imposé.
 * Sans cet aperçu, l'admin ne peut pas le déduire de l'écran.
 *
 * Service pur : aucun accès base, entièrement testable sur un modèle construit à la main.
 */
final class DotationModelePreview
{
    /**
     * N'affiche que les profils qui peuvent réellement recevoir ce kit, d'après ses cibles :
     * annoncer ce que « le dirigeant » recevrait d'un kit attribué à une équipe de joueurs n'est
     * pas une information, c'est du bruit.
     *
     * @param  DotationAffectation[] $affectations
     * @return DotationApercuProfil[]
     */
    public function build(DotationModele $modele, array $affectations = []): array
    {
        [$licencies, $dirigeants] = $this->publicsConcernes($affectations);

        $profils = [];

        if ($licencies) {
            $profils[] = $this->profil(
                $modele,
                'Nouveau licencié',
                'Première licence, ou arrivée d\'un autre club (mutation).',
                NatureLicence::NOUVELLE_DEMANDE,
                peutChoisir: true,
            );
            $profils[] = $this->profil(
                $modele,
                'Renouvellement',
                'Déjà licencié au club. Un licencié dont la nature est inconnue relève aussi de ce cas.',
                NatureLicence::RENOUVELLEMENT,
                peutChoisir: true,
            );
        }

        if ($dirigeants) {
            $profils[] = $this->profil(
                $modele,
                'Dirigeant',
                'Ne remplit pas de formulaire d\'inscription : il ne peut rien choisir lui-même.',
                null,
                peutChoisir: false,
            );
        }

        return $profils;
    }

    /**
     * Qui peut recevoir ce kit. Une cible catégorie, équipe ou licencié ne désigne que des
     * joueurs ; une cible rôle ou dirigeant que de l'encadrement ; une cible par défaut, les deux.
     * Sans aucune cible, on ne peut rien exclure — on montre tout.
     *
     * @param  DotationAffectation[] $affectations
     * @return array{0: bool, 1: bool} [licenciés concernés, dirigeants concernés]
     */
    private function publicsConcernes(array $affectations): array
    {
        if ($affectations === []) {
            return [true, true];
        }

        $licencies = false;
        $dirigeants = false;

        foreach ($affectations as $affectation) {
            $versDirigeant = $affectation->getDirigeant() !== null || $affectation->getRole() !== null;
            $versLicencie = $affectation->getCategory() !== null
                || $affectation->getTeam() !== null
                || $affectation->getLicencie() !== null;

            if ($versDirigeant) {
                $dirigeants = true;
            } elseif ($versLicencie) {
                $licencies = true;
            } else {
                // Défaut saison : tout le monde, joueurs comme encadrement.
                $licencies = true;
                $dirigeants = true;
            }
        }

        return [$licencies, $dirigeants];
    }

    private function profil(
        DotationModele $modele,
        string $nom,
        string $description,
        ?NatureLicence $nature,
        bool $peutChoisir,
    ): DotationApercuProfil {
        $fixes = [];
        $eligibles = [];
        $tous = [];

        foreach ($modele->getLignes() as $ligne) {
            $groupe = $ligne->getGroupeChoix();
            if ($groupe !== null) {
                $tous[$groupe] = true;
            }
            if (!$ligne->getEligibilite()->accepte($nature)) {
                continue;
            }
            if ($groupe === null) {
                $fixes[] = $ligne;
            } else {
                $eligibles[$groupe][] = $ligne;
            }
        }

        $imposes = [];
        $questions = [];
        $alertes = [];

        foreach (array_keys($tous) as $groupe) {
            $options = $eligibles[$groupe] ?? [];

            if ($options === []) {
                $alertes[] = sprintf(
                    'Aucune option éligible dans « %s » : ce profil ne recevra rien de ce choix.',
                    $groupe,
                );
                continue;
            }

            // Une seule option éligible : la question disparaît, l'article est imposé. C'est le
            // mécanisme qui donne la veste d'office aux nouveaux licenciés.
            if (count($options) === 1) {
                $imposes[] = ['groupe' => $groupe, 'ligne' => $options[0]];
                continue;
            }

            if (!$peutChoisir) {
                $imposes[] = ['groupe' => $groupe, 'ligne' => $options[0]];
                $alertes[] = sprintf(
                    '« %s » propose %d options, mais un dirigeant ne répond à aucun formulaire : il recevra « %s ».',
                    $groupe,
                    count($options),
                    $options[0]->getStockItem()->getNom(),
                );
                continue;
            }

            $questions[] = ['groupe' => $groupe, 'options' => $options];
        }

        if (!$peutChoisir) {
            foreach ($this->lignesPersonnalisees($fixes, $imposes) as $ligne) {
                $alertes[] = sprintf(
                    '« %s » réclame un texte à personnaliser, qu\'un dirigeant n\'a aucun moyen de saisir.',
                    $ligne->getStockItem()->getNom(),
                );
            }
        }

        return new DotationApercuProfil($nom, $description, $fixes, $imposes, $questions, $alertes);
    }

    /**
     * @param  DotationModeleLigne[]                                         $fixes
     * @param  array<int, array{groupe: string, ligne: DotationModeleLigne}> $imposes
     * @return DotationModeleLigne[]
     */
    private function lignesPersonnalisees(array $fixes, array $imposes): array
    {
        $lignes = array_merge($fixes, array_column($imposes, 'ligne'));

        return array_values(array_filter($lignes, static fn (DotationModeleLigne $l): bool => $l->isPersonnalisationRequise()));
    }
}
