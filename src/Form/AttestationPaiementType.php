<?php declare(strict_types=1);

namespace App\Form;

use App\DTO\AttestationPaiementData;
use App\Enum\Civilite;
use App\Enum\LienParente;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Les seuls champs d'une attestation de paiement.
 *
 * Ni montant, ni date, ni mode : ils viennent des paiements enregistrés. Ce formulaire
 * ne décrit que ce qu'aucune donnée du club ne sait dire — qui a payé, et à quel titre.
 *
 * @extends AbstractType<AttestationPaiementData>
 */
class AttestationPaiementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('destinataireCivilite', EnumType::class, [
                'class' => Civilite::class,
                'label' => 'Civilité',
                'choice_label' => fn (Civilite $c): string => $c->label(),
            ])
            ->add('destinatairePrenom', TextType::class, [
                'label' => 'Prénom',
                'constraints' => [new NotBlank(message: 'Le prénom du destinataire est obligatoire.'), new Length(max: 100)],
            ])
            ->add('destinataireNom', TextType::class, [
                'label' => 'Nom',
                'constraints' => [new NotBlank(message: 'Le nom du destinataire est obligatoire.'), new Length(max: 100)],
            ])
            ->add('lienParente', EnumType::class, [
                'class' => LienParente::class,
                'label' => 'Le destinataire a réglé la licence de…',
                'help' => 'Indéductible : la base ne connaît ni le sexe du licencié ni le nom de ses deux parents.',
                'choice_label' => fn (LienParente $l): string => $l->label(),
            ])
            ->add('email', EmailType::class, [
                'label' => 'Envoyer à',
                'help' => 'Laisser vide pour générer et archiver sans envoyer.',
                'required' => false,
                'constraints' => [new Email(message: 'Adresse email invalide.'), new Length(max: 180)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AttestationPaiementData::class]);
    }
}
