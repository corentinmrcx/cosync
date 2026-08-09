<?php declare(strict_types=1);

namespace App\Form;

use App\DTO\TeamSetupData;
use App\Entity\Category;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<TeamSetupData> */
class TeamSetupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de l\'équipe',
                'constraints' => [new NotBlank(), new Length(max: 100)],
                'attr' => ['placeholder' => 'ex: U15 A, Séniors 1, Loisirs'],
            ])
            ->add('categories', EntityType::class, [
                'label' => 'Catégories FFF associées',
                'class' => Category::class,
                'choice_label' => fn (Category $c) => $c->getCode(),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'query_builder' => fn (EntityRepository $repo) => $repo->createQueryBuilder('c')->orderBy('c.id', 'DESC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TeamSetupData::class]);
    }
}
