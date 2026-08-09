<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationGroupeReglagesData;
use App\DTO\DotationLigneReglagesData;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\DotationEligibilite;
use App\Repository\DotationBesoinRepository;
use App\Repository\DotationModeleRepository;
use App\Repository\LicencieRepository;
use App\Repository\StockItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Composition d'un kit de dotation : le modèle lui-même, ses articles, ses groupes de choix
 * et les réglages fins de chaque option (éligibilité, personnalisation).
 */
final class DotationModeleService
{
    /** Longueur retenue quand l'admin n'en fixe aucune : un flocage tient rarement plus long. */
    public const PERSONNALISATION_MAX_DEFAUT = 15;

    /** Un choix est une question : en dessous de deux options, l'article est imposé, pas choisi. */
    private const OPTIONS_MINIMUM_PAR_CHOIX = 2;

    /** Borne haute de sécurité — la colonne DotationBesoin::personnalisation fait 60. */
    private const PERSONNALISATION_MAX_ABSOLU = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DotationResolver $resolver,
        private readonly LicencieRepository $licencieRepository,
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly DotationModeleRepository $modeleRepository,
        private readonly StockItemRepository $itemRepository,
    ) {}

    /** @throws \DomainException si le nom est vide */
    public function creer(Season $season, string $nom): DotationModele
    {
        $nom = trim($nom);
        if ($nom === '') {
            throw new \DomainException('Le nom du modèle est obligatoire.');
        }

        $modele = (new DotationModele())->setSeason($season)->setNom($nom);

        $this->em->persist($modele);
        $this->em->flush();

        return $modele;
    }

    /** Un nom vide laisse l'ancien en place : l'admin peut ne vouloir basculer que l'activation. */
    public function mettreAJour(DotationModele $modele, string $nom, bool $actif): void
    {
        $nom = trim($nom);
        if ($nom !== '') {
            $modele->setNom($nom);
        }
        $modele->setActif($actif);

        $this->em->flush();
    }

    public function supprimer(DotationModele $modele): void
    {
        $this->em->remove($modele);
        $this->em->flush();
    }

    /** @throws \DomainException si l'article n'existe pas */
    public function ajouterArticle(DotationModele $modele, int $itemId, int $quantite): DotationModeleLigne
    {
        $item = $this->itemRepository->find($itemId);
        if ($item === null) {
            throw new \DomainException('Article introuvable.');
        }

        $ligne = (new DotationModeleLigne())
            ->setStockItem($item)
            ->setQuantite(max(1, $quantite));

        $modele->addLigne($ligne);
        $this->em->persist($ligne);
        $this->em->flush();

        return $ligne;
    }

    /**
     * Crée un groupe « 1 parmi N ». Les options naissent ouvertes à tout le monde : l'éligibilité
     * et le texte à personnaliser se règlent ensuite dans le panneau du choix, où l'aperçu montre
     * immédiatement la conséquence.
     *
     * @param int[] $itemIds
     *
     * @throws \DomainException si le nom est vide ou si moins de deux articles sont retenus
     */
    public function creerChoix(DotationModele $modele, string $nom, array $itemIds, int $quantite): int
    {
        $nom = trim($nom);
        if ($nom === '') {
            throw new \DomainException('Donnez un nom au choix (ex : « Veste »).');
        }

        $itemIds = array_unique(array_filter($itemIds));
        if (count($itemIds) < self::OPTIONS_MINIMUM_PAR_CHOIX) {
            throw new \DomainException('Un choix doit proposer au moins 2 articles.');
        }

        $quantite = max(1, $quantite);
        $ajoutes = 0;

        foreach ($itemIds as $itemId) {
            $item = $this->itemRepository->find($itemId);
            if ($item === null) {
                continue;
            }

            $modele->addLigne(
                $ligne = (new DotationModeleLigne())
                    ->setStockItem($item)
                    ->setQuantite($quantite)
                    ->setGroupeChoix($nom)
                    ->setEligibilite(DotationEligibilite::TOUS),
            );
            $this->em->persist($ligne);
            ++$ajoutes;
        }

        if ($ajoutes < self::OPTIONS_MINIMUM_PAR_CHOIX) {
            throw new \DomainException('Articles introuvables : choix non créé.');
        }

        $this->em->flush();

        return $ajoutes;
    }

    public function supprimerChoix(DotationModele $modele, string $groupe): void
    {
        foreach ($modele->getLignes() as $ligne) {
            if ($ligne->getGroupeChoix() === $groupe) {
                $this->em->remove($ligne);
            }
        }

        $this->em->flush();
    }

    public function updateReglages(DotationModeleLigne $ligne, DotationLigneReglagesData $data): void
    {
        $this->applyReglages($ligne, $data);
        $this->em->flush();
    }

    /**
     * Enregistre d'un coup les réglages de toutes les options d'un groupe : l'admin raisonne sur
     * le choix entier (« la veste aux nouveaux, le t-shirt aux autres »), pas option par option.
     *
     * Ne touche que les lignes appartenant réellement au groupe visé dans ce modèle : un id de
     * ligne posté au hasard est ignoré plutôt que d'atteindre le kit d'à côté.
     *
     * @return int nombre d'options mises à jour
     */
    public function updateReglagesGroupe(DotationModele $modele, string $groupe, DotationGroupeReglagesData $data): int
    {
        $modifiees = 0;
        foreach ($modele->getLignes() as $ligne) {
            if ($ligne->getGroupeChoix() !== $groupe) {
                continue;
            }
            $reglages = $data->parLigne[$ligne->getId()] ?? null;
            if ($reglages === null) {
                continue;
            }
            $this->applyReglages($ligne, $reglages);
            ++$modifiees;
        }

        $this->em->flush();

        return $modifiees;
    }

    private function applyReglages(DotationModeleLigne $ligne, DotationLigneReglagesData $data): void
    {
        $ligne->setEligibilite($data->eligibilite);
        $ligne->setPersonnalisationRequise($data->personnalisationRequise);

        if (!$data->personnalisationRequise) {
            // Une option qui ne demande plus de texte ne garde pas ses réglages orphelins.
            $ligne->setPersonnalisationLabel(null);
            $ligne->setPersonnalisationMaxLength(null);

            return;
        }

        $label = $data->personnalisationLabel !== null ? trim($data->personnalisationLabel) : '';
        $ligne->setPersonnalisationLabel($label !== '' ? $label : null);

        $max = $data->personnalisationMaxLength;
        $ligne->setPersonnalisationMaxLength(
            $max === null ? null : max(1, min($max, self::PERSONNALISATION_MAX_ABSOLU)),
        );
    }

    /**
     * Ajoute une option à un groupe existant. Sans cela, passer un choix de 2 à 3 articles
     * imposerait de le supprimer et de le recréer — en perdant les réglages des options déjà
     * faites, et les réponses déjà saisies par les licenciés.
     *
     * @throws \DomainException si le groupe n'existe pas ou propose déjà cet article
     */
    public function addOptionToGroupe(DotationModele $modele, string $groupe, StockItem $item): DotationModeleLigne
    {
        $existantes = [];
        foreach ($modele->getLignes() as $ligne) {
            if ($ligne->getGroupeChoix() === $groupe) {
                $existantes[] = $ligne;
            }
        }

        if ($existantes === []) {
            throw new \DomainException(sprintf('Aucun choix « %s » dans ce kit.', $groupe));
        }
        foreach ($existantes as $ligne) {
            if ($ligne->getStockItem()->getId() === $item->getId()) {
                throw new \DomainException(sprintf('« %s » est déjà proposé dans ce choix.', $item->getNom()));
            }
        }

        // La quantité est celle du groupe : un choix « 1 parmi N » remet toujours le même nombre
        // d'articles, quelle que soit l'option retenue.
        $ligne = (new DotationModeleLigne())
            ->setStockItem($item)
            ->setQuantite($existantes[0]->getQuantite())
            ->setGroupeChoix($groupe)
            ->setEligibilite(DotationEligibilite::TOUS);

        $modele->addLigne($ligne);
        $this->em->persist($ligne);
        $this->em->flush();

        return $ligne;
    }

    /**
     * Retire une ligne du kit.
     *
     * Un groupe de choix réduit à une seule option n'est plus une question : l'article devient
     * imposé silencieusement. Plutôt que de laisser l'admin produire cet état par accident, on
     * refuse et on l'oriente vers la suppression du choix entier.
     *
     * @throws \DomainException si la suppression laisserait un choix à moins de 2 options
     */
    public function removeLigne(DotationModeleLigne $ligne): void
    {
        $groupe = $ligne->getGroupeChoix();

        if ($groupe !== null) {
            $restantes = 0;
            foreach ($ligne->getModele()->getLignes() as $autre) {
                if ($autre->getGroupeChoix() === $groupe && $autre->getId() !== $ligne->getId()) {
                    ++$restantes;
                }
            }
            if ($restantes < self::OPTIONS_MINIMUM_PAR_CHOIX) {
                throw new \DomainException(sprintf('Un choix doit garder au moins 2 options. Retirez le choix « %s » en entier si vous n\'en voulez plus.', $groupe));
            }
        }

        $this->em->remove($ligne);
        $this->em->flush();
    }

    /**
     * Renomme un groupe de choix. Le nom du groupe est à la fois l'intitulé lu par le licencié
     * ET la clé sous laquelle sa réponse est stockée (`DossierClub.dotationChoix`,
     * `.dotationPersonnalisation`, `DotationBesoin.groupeChoix`) : renommer les lignes sans
     * migrer les réponses orphelinerait tout ce qui a déjà été saisi.
     *
     * @return int nombre de dossiers de licenciés migrés
     * @throws \DomainException si le nouveau nom est vide ou déjà porté par un autre groupe
     */
    public function renameGroupe(DotationModele $modele, string $ancien, string $nouveau): int
    {
        $nouveau = trim($nouveau);
        if ($nouveau === '') {
            throw new \DomainException('Le nom du choix ne peut pas être vide.');
        }
        if ($nouveau === $ancien) {
            return 0;
        }

        $lignesDuGroupe = [];
        foreach ($modele->getLignes() as $ligne) {
            $groupe = $ligne->getGroupeChoix();
            if ($groupe === $nouveau) {
                throw new \DomainException(sprintf('Un choix « %s » existe déjà dans ce kit.', $nouveau));
            }
            if ($groupe === $ancien) {
                $lignesDuGroupe[] = $ligne;
            }
        }

        if ($lignesDuGroupe === []) {
            throw new \DomainException(sprintf('Aucun choix « %s » dans ce kit.', $ancien));
        }

        $migres = $this->migrerReponses($modele, $ancien, $nouveau);

        foreach ($lignesDuGroupe as $ligne) {
            $ligne->setGroupeChoix($nouveau);
        }

        $this->em->flush();

        return $migres;
    }

    /**
     * Déplace la réponse stockée sous l'ancienne clé vers la nouvelle.
     *
     * Les clés JSON ne portent pas l'id du modèle. Tant qu'aucun autre kit de la saison n'a de
     * groupe du même nom, la clé est sans ambiguïté et toutes les réponses sont à migrer. Sinon
     * seulement, on départage par le kit réellement résolu pour chaque personne — un détour
     * qu'on évite quand il est inutile, d'autant que `resolveModele()` ignore les kits
     * désactivés et ne saurait pas rattacher les réponses d'un kit mis en sommeil.
     */
    private function migrerReponses(DotationModele $modele, string $ancien, string $nouveau): int
    {
        $ambigu = $this->groupePorteParUnAutreKit($modele, $ancien);
        $migres = 0;

        foreach ($this->licencieRepository->findBySeason($modele->getSeason()) as $licencie) {
            $dossier = $licencie->getDossierClub();
            if ($dossier === null) {
                continue;
            }

            $choix = $dossier->getDotationChoix() ?? [];
            $textes = $dossier->getDotationPersonnalisation() ?? [];
            if (!array_key_exists($ancien, $choix) && !array_key_exists($ancien, $textes)) {
                continue;
            }
            if ($ambigu && $this->resolver->resolveModele($licencie) !== $modele) {
                continue;
            }

            $dossier->setDotationChoix($this->renommerCle($choix, $ancien, $nouveau));
            $dossier->setDotationPersonnalisation($this->renommerCle($textes, $ancien, $nouveau));
            ++$migres;
        }

        // Les besoins déjà matérialisés, y compris ceux marqués DONNE : le groupe n'y est qu'un
        // libellé d'emplacement, le renommer ne change rien à ce qui a été remis.
        foreach ($this->besoinRepository->findBySeason($modele->getSeason()) as $besoin) {
            if ($besoin->getGroupeChoix() !== $ancien) {
                continue;
            }
            $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
            if ($ambigu && ($personne === null || $this->resolver->resolveModele($personne) !== $modele)) {
                continue;
            }
            $besoin->setGroupeChoix($nouveau);
        }

        return $migres;
    }

    private function groupePorteParUnAutreKit(DotationModele $modele, string $groupe): bool
    {
        foreach ($this->modeleRepository->findBySeason($modele->getSeason()) as $autre) {
            if ($autre === $modele) {
                continue;
            }
            foreach ($autre->getLignes() as $ligne) {
                if ($ligne->getGroupeChoix() === $groupe) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed> $valeurs
     * @return array<string, mixed>
     */
    private function renommerCle(array $valeurs, string $ancien, string $nouveau): array
    {
        if (!array_key_exists($ancien, $valeurs)) {
            return $valeurs;
        }

        $valeurs[$nouveau] = $valeurs[$ancien];
        unset($valeurs[$ancien]);

        return $valeurs;
    }

    /** Longueur maximale effective d'un texte de personnalisation pour cette ligne. */
    public function maxLengthFor(DotationModeleLigne $ligne): int
    {
        return $ligne->getPersonnalisationMaxLength() ?? self::PERSONNALISATION_MAX_DEFAUT;
    }
}
