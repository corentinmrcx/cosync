<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\Season;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Positive;

class SeasonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currentYear = (int) date('Y');
        $years = range($currentYear - 1, $currentYear + 4);
        $yearChoices = array_combine(
            array_map(static fn(int $y): string => (string) $y, $years),
            $years
        );

        $builder
            ->add('startYear', ChoiceType::class, [
                'label'   => 'Année de début',
                'choices' => $yearChoices,
                'data'    => $options['start_year'],
                'mapped'  => false,
            ])
            ->add('endYear', ChoiceType::class, [
                'label'   => 'Année de fin',
                'choices' => $yearChoices,
                'data'    => $options['end_year'],
                'mapped'  => false,
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
            ->add('active', CheckboxType::class, [
                'label'    => 'Saison active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $currentYear = (int) date('Y');

        $resolver->setDefaults([
            'data_class'   => Season::class,
            'start_year'   => $currentYear,
            'end_year'     => $currentYear + 1,
            'cout_jeunes'  => 85,
            'cout_seniors' => 120,
        ]);
    }
}
