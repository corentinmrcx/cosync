<?php declare(strict_types=1);

namespace App\Form;

use App\DTO\CategoryCreateData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class CategoryCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label'       => 'Code FootClubs',
                'constraints' => [new NotBlank(message: 'Le code est requis.')],
                'attr'        => ['placeholder' => 'ex: U20', 'style' => 'text-transform:uppercase'],
            ])
            ->add('label', TextType::class, [
                'label'       => 'Libellé',
                'constraints' => [new NotBlank(message: 'Le libellé est requis.')],
                'attr'        => ['placeholder' => 'ex: U20'],
            ])
            ->add('isEcoleFoot', CheckboxType::class, [
                'label'    => 'École de foot (U6–U13) — affiche les autorisations de transport',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CategoryCreateData::class]);
    }
}
