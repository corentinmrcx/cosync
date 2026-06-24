<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\StockCategory;
use App\Entity\StockItem;
use App\Enum\StockItemVetementType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

class StockItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // — Identification —
            ->add('nom', TextType::class, [
                'label'       => 'Nom de l\'article',
                'constraints' => [new NotBlank(), new Length(max: 150)],
                'attr'        => ['placeholder' => 'ex: Maillot domicile, Coca-Cola 33cl'],
            ])
            // marque, taille, couleur sont gérés via le composant text-combobox dans le template

            // — Catégorisation —
            ->add('category', EntityType::class, [
                'label'        => 'Catégorie',
                'class'        => StockCategory::class,
                'choice_label' => 'name',
                'placeholder'  => '— Sans catégorie —',
                'required'     => false,
            ])
            ->add('typeVetement', ChoiceType::class, [
                'label'    => 'Type de vêtement',
                'required' => false,
                'placeholder' => '— Non applicable —',
                'choices'  => array_combine(
                    array_map(fn(StockItemVetementType $t) => $t->label(), StockItemVetementType::cases()),
                    StockItemVetementType::cases(),
                ),
                'help' => 'Renseigner uniquement pour les articles vestimentaires. Permet les dotations automatiques.',
            ])

            // — Stock & budget —
            ->add('alertSeuil', IntegerType::class, [
                'label'       => 'Seuil d\'alerte',
                'required'    => false,
                'constraints' => [new PositiveOrZero()],
                'attr'        => ['placeholder' => 'ex: 5'],
                'help'        => 'Alerte orange quand le stock atteint ce seuil.',
            ])
            ->add('prixAchat', NumberType::class, [
                'label'    => 'Prix d\'achat unitaire (€)',
                'required' => false,
                'scale'    => 2,
                'attr'     => ['placeholder' => 'ex: 12.50'],
                'help'     => 'Pour le suivi budgétaire. Non affiché aux licenciés.',
            ])

            // — Référence —
            ->add('refCatalogue', TextType::class, [
                'label'    => 'Référence catalogue',
                'required' => false,
                'attr'     => ['placeholder' => 'ex: NK-2025-001'],
            ])
            ->add('lienAchat', TextType::class, [
                'label'    => 'Lien d\'achat',
                'required' => false,
                'attr'     => ['placeholder' => 'https://…'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StockItem::class]);
    }
}
