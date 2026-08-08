<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\DTO\DotationApercuProfil;
use App\Entity\DotationAffectation;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\StockItem;
use App\Entity\Team;
use App\Enum\DirigeantRole;
use App\Enum\DotationEligibilite;
use App\Service\Stock\DotationModelePreview;
use PHPUnit\Framework\TestCase;

/**
 * L'aperçu affiché sous les réglages d'un kit. Service pur : pas de base, pas de conteneur.
 *
 * Ce qu'il doit rendre visible, parce que rien d'autre dans l'écran ne le dit : réserver une
 * option à une population ne filtre pas une liste, cela SUPPRIME la question pour les autres.
 */
final class DotationModelePreviewTest extends TestCase
{
    private function modele(DotationModeleLigne ...$lignes): DotationModele
    {
        $modele = new DotationModele();
        foreach ($lignes as $ligne) {
            $modele->addLigne($ligne);
        }

        return $modele;
    }

    private function ligne(
        string $nom,
        ?string $groupe = null,
        DotationEligibilite $eligibilite = DotationEligibilite::TOUS,
        bool $personnalisation = false,
    ): DotationModeleLigne {
        return (new DotationModeleLigne())
            ->setStockItem((new StockItem())->setNom($nom))
            ->setQuantite(1)
            ->setGroupeChoix($groupe)
            ->setEligibilite($eligibilite)
            ->setPersonnalisationRequise($personnalisation);
    }

    /** @param DotationApercuProfil[] $apercu */
    private function profil(array $apercu, string $nom): DotationApercuProfil
    {
        foreach ($apercu as $p) {
            if ($p->nom === $nom) {
                return $p;
            }
        }

        self::fail(sprintf('Profil « %s » absent de l\'aperçu.', $nom));
    }

    private function affectation(callable $cible): DotationAffectation
    {
        $a = new DotationAffectation();
        $cible($a);

        return $a;
    }

    /**
     * Un kit attribué à une équipe ne sera jamais porté par un dirigeant : annoncer ce qu'il
     * recevrait n'est pas une information, c'est du bruit dans l'écran.
     */
    public function testUnKitAttribueAUneEquipeNAnnoncePasLeProfilDirigeant(): void
    {
        $modele = $this->modele($this->ligne('Maillot'));
        $equipe = $this->affectation(static fn (DotationAffectation $a) => $a->setTeam(new Team()));

        $profils = array_column((new DotationModelePreview())->build($modele, [$equipe]), 'nom');

        self::assertSame(['Nouveau licencié', 'Renouvellement'], $profils);
    }

    public function testUnKitAttribueAUnRoleNAnnonceQueLeProfilDirigeant(): void
    {
        $modele = $this->modele($this->ligne('Polo'));
        $role = $this->affectation(static fn (DotationAffectation $a) => $a->setRole(DirigeantRole::RESPONSABLE_FOOT));

        $profils = array_column((new DotationModelePreview())->build($modele, [$role]), 'nom');

        self::assertSame(['Dirigeant'], $profils);
    }

    /** Cible par défaut : le kit part à toute la saison, joueurs comme encadrement. */
    public function testUnKitParDefautAnnonceTousLesProfils(): void
    {
        $modele = $this->modele($this->ligne('Sweat'));
        $defaut = $this->affectation(static fn (DotationAffectation $a) => $a);

        $profils = array_column((new DotationModelePreview())->build($modele, [$defaut]), 'nom');

        self::assertSame(['Nouveau licencié', 'Renouvellement', 'Dirigeant'], $profils);
    }

    /** Sans aucune cible, on ne peut rien exclure : l'aperçu reste complet. */
    public function testUnKitSansCibleAnnonceTousLesProfils(): void
    {
        $profils = array_column((new DotationModelePreview())->build($this->modele($this->ligne('Sweat'))), 'nom');

        self::assertSame(['Nouveau licencié', 'Renouvellement', 'Dirigeant'], $profils);
    }

    public function testUneOptionReserveeAuxNouveauxLeurEstImposeeSansQuestion(): void
    {
        $modele = $this->modele(
            $this->ligne('Veste', 'Votre dotation', DotationEligibilite::NOUVEAUX),
            $this->ligne('T-shirt', 'Votre dotation', DotationEligibilite::RENOUVELLEMENTS),
        );

        $apercu = (new DotationModelePreview())->build($modele);

        $nouveau = $this->profil($apercu, 'Nouveau licencié');
        self::assertSame([], $nouveau->questions, 'Une seule option éligible : plus de choix.');
        self::assertSame('Veste', $nouveau->imposes[0]['ligne']->getStockItem()->getNom());

        $renouvellement = $this->profil($apercu, 'Renouvellement');
        self::assertSame('T-shirt', $renouvellement->imposes[0]['ligne']->getStockItem()->getNom());
    }

    public function testDeuxOptionsOuvertesRestentUneQuestion(): void
    {
        $modele = $this->modele(
            $this->ligne('Veste', 'Votre dotation'),
            $this->ligne('T-shirt', 'Votre dotation'),
        );

        $renouvellement = $this->profil((new DotationModelePreview())->build($modele), 'Renouvellement');

        self::assertSame([], $renouvellement->imposes);
        self::assertCount(2, $renouvellement->questions[0]['options']);
        self::assertSame('Votre dotation', $renouvellement->questions[0]['groupe']);
    }

    public function testUnGroupeSansAucuneOptionEligibleEstSignale(): void
    {
        // Les deux options réservées aux nouveaux : un renouvellement ne reçoit rien de ce choix,
        // et rien ailleurs dans l'écran ne l'aurait laissé deviner.
        $modele = $this->modele(
            $this->ligne('Veste', 'Votre dotation', DotationEligibilite::NOUVEAUX),
            $this->ligne('Sweat', 'Votre dotation', DotationEligibilite::NOUVEAUX),
        );

        $renouvellement = $this->profil((new DotationModelePreview())->build($modele), 'Renouvellement');

        self::assertTrue($renouvellement->neRecoitRien());
        self::assertStringContainsString('Aucune option éligible', $renouvellement->alertes[0]);
    }

    public function testUnDirigeantNeChoisitPasEtEstAverti(): void
    {
        $modele = $this->modele(
            $this->ligne('Veste', 'Votre dotation'),
            $this->ligne('T-shirt', 'Votre dotation', DotationEligibilite::TOUS, personnalisation: true),
        );

        $dirigeant = $this->profil((new DotationModelePreview())->build($modele), 'Dirigeant');

        self::assertSame([], $dirigeant->questions, 'Un dirigeant ne remplit aucun formulaire.');
        self::assertSame('Veste', $dirigeant->imposes[0]['ligne']->getStockItem()->getNom());
        self::assertStringContainsString('il recevra « Veste »', $dirigeant->alertes[0]);
    }

    public function testUnArticleFloqueImposeAUnDirigeantEstSignale(): void
    {
        $modele = $this->modele($this->ligne('T-shirt', null, DotationEligibilite::TOUS, personnalisation: true));

        $dirigeant = $this->profil((new DotationModelePreview())->build($modele), 'Dirigeant');

        self::assertStringContainsString('aucun moyen de saisir', $dirigeant->alertes[0]);
    }
}
