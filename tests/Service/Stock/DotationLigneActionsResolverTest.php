<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Enum\DotationBesoinStatut;
use App\Enum\DotationLigneAction;
use App\Enum\Permission;
use App\Service\Dotation\DotationLigneActionsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quel geste une ligne du suivi propose-t-elle ?
 *
 * La cellule alignait « Marquer remis » et « Dé-préparer » côte à côte : la colonne changeait de
 * largeur d'une ligne à l'autre, et mettait sur le même plan avancer et annuler. Un bouton pour
 * l'étape en cours ; le retour en arrière part en bas du menu « ⋯ » de la ligne.
 */
final class DotationLigneActionsResolverTest extends TestCase
{
    public function testUneLigneAServirProposeLaPreparation(): void
    {
        $actions = $this->resolver()->pour($this->besoin(DotationBesoinStatut::A_DONNER));

        self::assertSame(DotationLigneAction::PREPARER, $actions->principale);
        self::assertNull($actions->retour, 'Rien n\'est encore franchi : il n\'y a rien à défaire.');
    }

    public function testUnSacPretProposeLaRemiseEtSonRetour(): void
    {
        $actions = $this->resolver()->pour($this->besoin(DotationBesoinStatut::PREPARE));

        self::assertSame(DotationLigneAction::REMETTRE, $actions->principale);
        self::assertSame(DotationLigneAction::DEPREPARER, $actions->retour);
    }

    public function testUneLigneRemiseNeProposePlusQueDeDefaire(): void
    {
        $actions = $this->resolver()->pour($this->besoin(DotationBesoinStatut::DONNE));

        self::assertNull($actions->principale, 'Le parcours est terminé : plus aucune étape à proposer.');
        self::assertSame(DotationLigneAction::ANNULER_REMISE, $actions->retour);
    }

    /**
     * Le bénévole qui remplit les sacs au local n'a pas à sortir l'équipement du stock : sur une
     * ligne préparée, il défait sa propre préparation mais ne voit pas « Marquer remis ».
     */
    public function testUnComptePreparateurNeSeVoitProposerQueLaPreparation(): void
    {
        $resolver = $this->resolver(Permission::DOTATION_PREPARER);

        self::assertSame(
            DotationLigneAction::PREPARER,
            $resolver->pour($this->besoin(DotationBesoinStatut::A_DONNER))->principale,
        );

        $prepare = $resolver->pour($this->besoin(DotationBesoinStatut::PREPARE));
        self::assertNull($prepare->principale, 'La remise n\'est pas son geste.');
        self::assertSame(DotationLigneAction::DEPREPARER, $prepare->retour);

        $donne = $resolver->pour($this->besoin(DotationBesoinStatut::DONNE));
        self::assertNull($donne->principale, 'Une ligne déjà remise ne lui propose rien du tout…');
        self::assertNull($donne->retour, '…pas même de la défaire : le stock ne le regarde pas.');
    }

    private function resolver(Permission ...$accordees): DotationLigneActionsResolver
    {
        $valeurs = array_map(static fn (Permission $p): string => $p->value, $accordees);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribut): bool => $valeurs === [] || in_array($attribut, $valeurs, true),
        );

        return new DotationLigneActionsResolver($security);
    }

    private function besoin(DotationBesoinStatut $statut): DotationBesoin
    {
        return (new DotationBesoin())->setStatut($statut);
    }
}
