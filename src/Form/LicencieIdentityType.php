<?php declare(strict_types=1);

namespace App\Form;

use App\DTO\LicencieIdentityData;
use App\Entity\Category;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\LessThan;
use Symfony\Component\Validator\Constraints\NotBlank;

class LicencieIdentityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label'       => 'Nom',
                'constraints' => [new NotBlank(message: 'Le nom est requis.')],
                'attr'        => ['placeholder' => 'DUPONT'],
            ])
            ->add('prenom', TextType::class, [
                'label'       => 'Prénom',
                'constraints' => [new NotBlank(message: 'Le prénom est requis.')],
                'attr'        => ['placeholder' => 'Thomas'],
            ])
            ->add('dateNaissance', DateType::class, [
                'label'       => 'Date de naissance',
                'widget'      => 'single_text',
                'input'       => 'datetime_immutable',
                'constraints' => [
                    new NotBlank(message: 'La date de naissance est requise.'),
                    new LessThan(value: 'today', message: 'La date de naissance doit être dans le passé.'),
                ],
            ])
            ->add('category', EntityType::class, [
                'class'        => Category::class,
                'choice_label' => fn(Category $c): string => $c->getCode(),
                'label'        => 'Catégorie',
                'placeholder'  => '— Sélectionner —',
                'constraints'  => [new NotBlank(message: 'La catégorie est requise.')],
            ])
            ->add('email', EmailType::class, [
                'label'    => 'Email',
                'required' => false,
                'attr'     => ['placeholder' => 'parent@email.fr'],
            ])
            ->add('telephone', TextType::class, [
                'label'    => 'Téléphone',
                'required' => false,
                'attr'     => ['placeholder' => '06 12 34 56 78'],
            ])
            ->add('voieRue', TextType::class, [
                'label'    => 'Adresse',
                'required' => false,
                'attr'     => ['placeholder' => '12 rue de la Mairie'],
            ])
            ->add('codePostal', TextType::class, [
                'label'    => 'Code postal',
                'required' => false,
                'attr'     => ['placeholder' => '51320'],
            ])
            ->add('ville', TextType::class, [
                'label'    => 'Ville',
                'required' => false,
                'attr'     => ['placeholder' => 'Soudron'],
            ])
            ->add('numLicence', TextType::class, [
                'label'    => 'Numéro FootClubs',
                'required' => false,
                'attr'     => ['placeholder' => 'Ex : 123456'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LicencieIdentityData::class]);
    }
}
