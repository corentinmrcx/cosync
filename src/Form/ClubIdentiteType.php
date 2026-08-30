<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\ClubSettings;
use App\Enum\Civilite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Identité de l'association et signataire de ses attestations.
 *
 * Ces valeurs étaient jusqu'ici écrites en dur dans les templates. Elles remontent ici
 * parce qu'une attestation de paiement les engage juridiquement — et parce que le
 * signataire, lui, change tous les deux ou trois ans.
 *
 * @extends AbstractType<ClubSettings>
 */
class ClubIdentiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('associationNom', TextType::class, [
                'label' => 'Nom de l\'association',
                'required' => false,
                'attr' => ['placeholder' => 'Foyer de Soudron'],
                'constraints' => [new Length(max: 150)],
            ])
            ->add('associationAdresse', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => ['placeholder' => '1 Rue de l\'Église'],
                'constraints' => [new Length(max: 200)],
            ])
            ->add('associationCodePostal', TextType::class, [
                'label' => 'Code postal',
                'required' => false,
                'attr' => ['placeholder' => '51320'],
                'constraints' => [new Length(max: 10)],
            ])
            ->add('associationVille', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => ['placeholder' => 'Soudron'],
                'constraints' => [new Length(max: 100)],
            ])
            ->add('associationSiret', TextType::class, [
                'label' => 'N° SIRET',
                'required' => false,
                'attr' => ['placeholder' => '488 728 794 00010'],
                'constraints' => [new Length(max: 20)],
            ])
            ->add('associationEmail', EmailType::class, [
                'label' => 'Email de contact',
                'help' => 'Adresse figurant sur les documents officiels du club.',
                'required' => false,
                'attr' => ['placeholder' => 'foyerdesoudron@gmail.com'],
                'constraints' => [new Length(max: 180)],
            ])
            ->add('signataireCivilite', EnumType::class, [
                'class' => Civilite::class,
                'label' => 'Civilité',
                'required' => false,
                'placeholder' => '—',
                'choice_label' => fn (Civilite $c): string => $c->label(),
            ])
            ->add('signataireNom', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'attr' => ['placeholder' => 'Claudine Moreaux'],
                'constraints' => [new Length(max: 100)],
            ])
            ->add('signataireQualite', TextType::class, [
                'label' => 'Qualité',
                'help' => 'Ex. : trésorière, président, secrétaire.',
                'required' => false,
                'attr' => ['placeholder' => 'trésorière'],
                'constraints' => [new Length(max: 100)],
            ])
            ->add('signatureFichier', FileType::class, [
                'label' => 'Signature et cachet (image)',
                'help' => 'Facultatif. Sans image, l\'attestation s\'imprime avec un cadre à signer à la main. PNG ou JPEG, 2 Mo maximum.',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new File(
                        maxSize: '2M',
                        mimeTypes: ['image/png', 'image/jpeg'],
                        mimeTypesMessage: 'Format non reconnu : attendu PNG ou JPEG.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClubSettings::class]);
    }
}
