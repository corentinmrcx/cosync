<?php declare(strict_types=1);

namespace App\Form;

use App\DTO\LicencieCreateData;
use App\Entity\Category;
use App\Entity\Team;
use App\Enum\NatureLicence;
use App\Repository\TeamRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<LicencieCreateData> */
class LicencieCreateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'constraints' => [new NotBlank(message: 'Le nom est requis.')],
                'attr' => ['placeholder' => 'DUPONT'],
            ])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'constraints' => [new NotBlank(message: 'Le prénom est requis.')],
                'attr' => ['placeholder' => 'Thomas'],
            ])
            ->add('dateNaissance', DateType::class, [
                'label' => 'Date de naissance',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['max' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')],
                'constraints' => [
                    new NotBlank(message: 'La date de naissance est requise.'),
                    new LessThan(value: 'today', message: 'La date de naissance doit être dans le passé.'),
                ],
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => fn (Category $c): string => $c->getCode(),
                'label' => 'Catégorie',
                'placeholder' => '— Sélectionner —',
                'constraints' => [new NotBlank(message: 'La catégorie est requise.')],
            ])
            ->add('team', EntityType::class, [
                'class' => Team::class,
                'choice_label' => 'name',
                'label' => 'Équipe',
                'required' => false,
                'placeholder' => '— Aucune équipe —',
                'query_builder' => static fn (TeamRepository $repo) => $repo->createQueryBuilder('t')
                    ->where('t.season = :season')
                    ->setParameter('season', $options['season'])
                    ->orderBy('t.name', 'ASC'),
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
                'attr' => ['placeholder' => 'parent@email.fr'],
            ])
            ->add('telephone', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['placeholder' => '06 12 34 56 78'],
            ])
            ->add('voieRue', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => ['placeholder' => '12 rue de la Mairie'],
            ])
            ->add('codePostal', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
                'attr' => ['placeholder' => '51320'],
            ])
            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => ['placeholder' => 'Soudron'],
            ])
            ->add('numLicence', TextType::class, [
                'label' => 'Numéro FootClubs',
                'required' => false,
                'attr' => ['placeholder' => 'Ex : 123456 (facultatif)'],
            ])
            ->add('natureLicence', EnumType::class, [
                'class' => NatureLicence::class,
                'label' => 'Nature de la licence',
                'help' => 'Détermine les options de dotation proposées au licencié.',
                'required' => false,
                'placeholder' => '— Inconnue —',
                'choice_label' => static fn (NatureLicence $nature): string => $nature->label(),
            ])
            // Décochée : rien ne part sans décision. Un lien s'envoie ensuite depuis la fiche
            // ou par l'envoi groupé, une fois l'équipe du licencié connue.
            ->add('sendLink', CheckboxType::class, [
                'label' => 'Envoyer le lien d\'inscription par email',
                'help' => 'Sans équipe, le formulaire annoncera la cotisation par défaut de la saison et une dotation incomplète.',
                'mapped' => false,
                'required' => false,
                'data' => false,
            ])
        ;

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if ($form->get('sendLink')->getData() === true && !$form->get('email')->getData()) {
                $form->get('email')->addError(
                    new FormError('Un email est requis pour envoyer le lien d\'inscription.')
                );
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LicencieCreateData::class,
            'season' => null,
        ]);
    }
}
