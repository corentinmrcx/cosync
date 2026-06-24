<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\Season;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

class SeasonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startYear', IntegerType::class, [
                'label'       => 'Année de début',
                'mapped'      => false,
                'data'        => $options['start_year'],
                'attr'        => ['min' => 2000, 'step' => 1, 'placeholder' => (int) date('Y')],
                'constraints' => [new Range(min: 2000)],
            ])
            ->add('coutJeunes', IntegerType::class, [
                'label'       => 'Cotisation jeunes (€)',
                'mapped'      => false,
                'data'        => $options['cout_jeunes'],
                'constraints' => [new Positive()],
            ])
            ->add('coutSeniors', IntegerType::class, [
                'label'       => 'Cotisation seniors (€)',
                'mapped'      => false,
                'data'        => $options['cout_seniors'],
                'constraints' => [new Positive()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'   => Season::class,
            'start_year'   => (int) date('Y'),
            'cout_jeunes'  => 85,
            'cout_seniors' => 120,
        ]);
    }
}
