<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\Season;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
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
            ->add('cotisationDefaut', IntegerType::class, [
                'label'       => 'Cotisation par défaut (€)',
                'help'        => 'Appliquée à un licencié sans équipe, ou dont l\'équipe n\'a pas de cotisation propre.',
                'attr'        => ['min' => 0, 'step' => 1, 'placeholder' => '85'],
                'constraints' => [new PositiveOrZero()],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Season::class,
            'start_year' => (int) date('Y'),
        ]);
    }
}
