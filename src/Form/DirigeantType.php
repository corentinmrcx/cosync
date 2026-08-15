<?php declare(strict_types=1);

namespace App\Form;

use App\DTO\DirigeantData;
use App\Entity\Licencie;
use App\Entity\Team;
use App\Enum\DirigeantRole;
use App\Enum\TailleType;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use App\Service\Referentiel\TailleReferentiel;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<DirigeantData> */
class DirigeantType extends AbstractType
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
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'constraints' => [new NotBlank(message: 'Le nom est requis.')],
                'attr' => ['placeholder' => 'DUPONT'],
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'constraints' => [new NotBlank(message: 'Le prénom est requis.')],
                'attr' => ['placeholder' => 'Jean'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
                'attr' => ['placeholder' => 'jean.dupont@email.fr'],
            ])
            ->add('telephone', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['placeholder' => '06 12 34 56 78'],
            ])
            ->add('dateNaissance', DateType::class, [
                'label' => 'Date de naissance',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['max' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')],
                'constraints' => [
                    new LessThan(value: 'today', message: 'La date de naissance doit être dans le passé.'),
                ],
            ])
            ->add('role', EnumType::class, [
                'class' => DirigeantRole::class,
                'label' => 'Rôle',
                'required' => true,
                'choice_label' => static fn (DirigeantRole $role) => $role->label(),
            ])
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
            ->add('numLicence', TextType::class, [
                'label' => 'Numéro FootClubs',
                'required' => false,
                'attr' => ['placeholder' => 'Ex : 123456 (facultatif)'],
            ])
            ->add('tailleHaut', ChoiceType::class, [
                'label' => 'Taille haut',
                'required' => false,
                'placeholder' => '— Non renseignée —',
                'choices' => $tailleChoices,
            ])
            ->add('tailleBas', ChoiceType::class, [
                'label' => 'Taille bas',
                'required' => false,
                'placeholder' => '— Non renseignée —',
                'choices' => $tailleChoices,
            ])
            ->add('pointure', ChoiceType::class, [
                'label' => 'Pointure',
                'required' => false,
                'placeholder' => '— Non renseignée —',
                'choices' => $pointureChoices,
            ])
            ->add('licencie', EntityType::class, [
                'class' => Licencie::class,
                'choice_label' => 'nomPrenom',
                'label' => 'Lien profil joueur',
                'required' => false,
                'placeholder' => '— Ce dirigeant n\'est pas joueur —',
                'query_builder' => static fn (LicencieRepository $repo) => $repo->createQueryBuilder('l')
                    ->where('l.season = :season')
                    ->setParameter('season', $season)
                    ->orderBy('l.nom', 'ASC'),
                'help' => 'À renseigner si ce dirigeant est également licencié joueur cette saison.',
            ])
        ;

        // Uniquement à la création, et décochée : rien ne part sans décision. Le lien
        // s'envoie ensuite depuis la fiche ou par l'envoi groupé.
        if ($options['envoi_lien'] === true) {
            $builder->add('sendLink', CheckboxType::class, [
                'label' => 'Envoyer le lien du formulaire par email',
                'help' => 'Le dirigeant y renseigne ses tailles, ses autorisations et signe les documents de la saison.',
                'mapped' => false,
                'required' => false,
                'data' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DirigeantData::class,
            'season' => null,
            'envoi_lien' => false,
        ]);
    }
}
