<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\Licencie;
use App\Entity\Team;
use App\Enum\NatureLicence;
use App\Enum\TailleType;
use App\Repository\TeamRepository;
use App\Service\Referentiel\TailleReferentiel;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<Licencie> */
class LicencieEditType extends AbstractType
{
    public function __construct(
        private readonly TailleReferentiel $tailles,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tailleChoices = $this->tailles->choixGroupes(TailleType::VETEMENT);
        $pointureChoices = $this->tailles->choixGroupes(TailleType::POINTURE);

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
