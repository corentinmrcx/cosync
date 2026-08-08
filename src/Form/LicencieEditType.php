<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\Licencie;
use App\Entity\Team;
use App\Enum\NatureLicence;
use App\Repository\TeamRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<Licencie> */
class LicencieEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tailleChoices = [
            'Adulte' => ['XS' => 'XS', 'S' => 'S', 'M' => 'M', 'L' => 'L', 'XL' => 'XL', 'XXL' => 'XXL', '3XL' => '3XL', '4XL' => '4XL'],
            'Enfant' => [
                '6 ans' => '6 ans', '8 ans' => '8 ans', '10 ans' => '10 ans',
                '12 ans' => '12 ans', '14 ans' => '14 ans', '16 ans' => '16 ans',
            ],
        ];

        $pointureChoices = [];
        foreach (range(24, 50) as $p) {
            $pointureChoices[(string) $p] = (string) $p;
        }

        $season = $options['season'];

        $builder
            ->add('team', EntityType::class, [
                'class' => Team::class,
                'choice_label' => 'name',
                'label' => 'Équipe',
                'required' => false,
                'placeholder' => '— Aucune équipe —',
                'query_builder' => static fn (TeamRepository $repo) => $repo->createQueryBuilder('t')
                    ->where('t.season = :season')
                    ->setParameter('season', $season)
                    ->orderBy('t.name', 'ASC'),
            ])
            ->add('tailleHaut', ChoiceType::class, [
                'label' => 'Taille haut',
                'mapped' => false,
                'required' => false,
                'placeholder' => '— Non renseignée —',
                'choices' => $tailleChoices,
                'data' => $options['taille_haut'],
            ])
            ->add('tailleBas', ChoiceType::class, [
                'label' => 'Taille bas',
                'mapped' => false,
                'required' => false,
                'placeholder' => '— Non renseignée —',
                'choices' => $tailleChoices,
                'data' => $options['taille_bas'],
            ])
            ->add('pointure', ChoiceType::class, [
                'label' => 'Pointure',
                'mapped' => false,
                'required' => false,
                'placeholder' => '— Non renseignée —',
                'choices' => $pointureChoices,
                'data' => $options['pointure'],
            ])
            ->add('natureLicence', EnumType::class, [
                'class' => NatureLicence::class,
                'label' => 'Nature de la licence',
                'help' => 'Détermine les options de dotation proposées au licencié.',
                'mapped' => false,
                'required' => false,
                'placeholder' => '— Inconnue —',
                'choice_label' => static fn (NatureLicence $nature): string => $nature->label(),
                'data' => $options['nature_licence'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Licencie::class,
            'season' => null,
            'taille_haut' => null,
            'taille_bas' => null,
            'pointure' => null,
            'nature_licence' => null,
        ]);
    }
}
