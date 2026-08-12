<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\ClubSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Lien de la boutique du club. Le champ vise la même ligne unique que
 * {@see ClubSettingsType} : les deux écrans sont séparés, la table ne l'est pas.
 *
 * La contrainte Url n'est pas cosmétique : ce lien est rendu tel quel dans un href, sur
 * la page de confirmation publique et dans un mail. Elle limite aux schémas http/https.
 *
 * @extends AbstractType<ClubSettings>
 */
class BoutiqueSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('boutiqueUrl', UrlType::class, [
                'label' => 'Lien de la boutique',
                'help' => 'Laisser vide tant que la boutique n\'est pas ouverte : elle n\'apparaîtra alors ni sur la page de confirmation, ni dans les mails.',
                'required' => false,
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'https://www.helloasso.com/associations/…/boutiques/…'],
                'constraints' => [
                    new Url(
                        message: 'Ce lien n\'est pas une adresse web valide (elle doit commencer par https://).',
                        requireTld: true,
                    ),
                    new Length(max: 255),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClubSettings::class]);
    }
}
