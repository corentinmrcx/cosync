<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\StockCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<StockCategory> */
class StockCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'constraints' => [new NotBlank(), new Length(max: 100)],
                'attr' => ['placeholder' => 'ex: Buvette'],
            ])
            ->add('position', IntegerType::class, [
                'label' => 'Ordre d\'affichage',
                'attr' => ['placeholder' => '0'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => StockCategory::class]);
    }
}
