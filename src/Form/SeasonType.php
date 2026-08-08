<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\Season;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
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
            ->add('iban', TextType::class, [
                'label'    => 'IBAN du club',
                'help'     => 'Laisser vide si le club ne propose pas le virement : l\'option n\'apparaîtra alors pas dans le formulaire d\'inscription.',
                'required' => false,
                'attr'     => ['placeholder' => 'FR76 1234 5678 9012 3456 7890 123'],
                // Longueur maximale d'un IBAN (norme ISO 13616), espaces de lisibilité compris.
                'constraints' => [new Length(max: 34)],
            ])
            ->add('bic', TextType::class, [
                'label'       => 'BIC',
                'required'    => false,
                'attr'        => ['placeholder' => 'AGRIFRPP802'],
                'constraints' => [new Length(max: 11)],
            ])
            ->add('titulaireCompte', TextType::class, [
                'label'       => 'Titulaire du compte',
                'help'        => 'Nom exact figurant sur le compte — sert aussi d\'ordre pour les chèques.',
                'required'    => false,
                'attr'        => ['placeholder' => 'Foyer de Soudron'],
                'constraints' => [new Length(max: 100)],
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
