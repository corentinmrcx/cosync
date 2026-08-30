<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\ClubSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Réglages de la relance automatique.
 *
 * Les bornes ne sont pas décoratives : un délai d'un jour ferait écrire tous les matins, un
 * plafond à vingt reviendrait à n'en avoir aucun. Elles encadrent un automate qui écrit à
 * de vraies personnes sans que personne ne relise.
 *
 * @extends AbstractType<ClubSettings>
 */
class RelanceSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('relanceActive', CheckboxType::class, [
                'label' => 'Relancer automatiquement',
                'help' => 'Une passe par jour à 9 h. Décoché, rien ne part de soi-même — l\'écran de relance groupée reste utilisable.',
                'required' => false,
            ])
            ->add('relanceDelaiJours', IntegerType::class, [
                'label' => 'Délai avant relance (jours)',
                'help' => 'Compté depuis le dernier mail reçu par la personne, quel qu\'il soit. Une relance passée à la main repousse donc d\'autant celle du robot.',
                'constraints' => [new Range(min: 3, max: 90)],
            ])
            ->add('relanceMax', IntegerType::class, [
                'label' => 'Nombre maximum de relances',
                'help' => 'Par personne et par saison. Au-delà, la personne n\'est plus relancée par mail : elle se rattrape au téléphone.',
                'constraints' => [new Range(min: 1, max: 10)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClubSettings::class]);
    }
}
