<?php declare(strict_types=1);

namespace App\Form;

use App\DTO\TeamSetupData;
use App\Entity\Category;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

class TeamSetupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EntityType::class, [
                'label'        => 'Catégorie FFF',
                'class'        => Category::class,
                'choice_label'  => fn(Category $c) => $c->getCode(),
                'placeholder'   => '— Choisir une catégorie —',
                'query_builder' => fn(\Doctrine\ORM\EntityRepository $repo) => $repo->createQueryBuilder('c')->orderBy('c.id', 'DESC'),
                'constraints'  => [new NotNull(message: 'Sélectionnez une catégorie.')],
            ])
            ->add('suffix', TextType::class, [
                'label'       => 'Suffixe',
                'required'    => false,
                'constraints' => [new Length(max: 10)],
                'attr'        => ['placeholder' => 'ex: A, B, 1, 2'],
                'help'        => 'Optionnel. Utilisez A/B pour plusieurs équipes de même catégorie.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TeamSetupData::class]);
    }
}
