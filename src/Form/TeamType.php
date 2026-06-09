<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\Team;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class TeamType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'Nom de l\'équipe',
                'constraints' => [new NotBlank(), new Length(max: 100)],
                'attr'        => ['placeholder' => 'ex: U15 A, Séniors 1, Loisirs'],
            ])
            ->add('defaultCategory', EntityType::class, [
                'label'        => 'Catégorie FFF par défaut',
                'class'        => Category::class,
                'choice_label' => fn(Category $c) => $c->getCode() . ' — ' . $c->getLabel(),
                'placeholder'  => '— Aucune (ex: Loisirs, Dirigeants) —',
                'required'     => false,
                'help'         => 'Si renseignée, les licenciés importés avec cette catégorie seront automatiquement assignés à cette équipe.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Team::class]);
    }
}
