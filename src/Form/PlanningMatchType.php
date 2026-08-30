<?php declare(strict_types=1);

namespace App\Form;

use App\DTO\Planning\PlanningMatchData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie d'un match à la main : la reprise du formulaire d'origine, en une rangée.
 *
 * L'heure est un `TimeType` en `input: 'string'` : le navigateur affiche son sélecteur
 * d'heure, et le DTO reçoit `HH:MM` — jamais un `DateTime`, donc jamais un fuseau
 * horaire dans un libellé qu'on imprime (cf. MatchDomicile::$heure).
 *
 * ⚠️ Ne pas revenir à un `TextType` avec `attr: {type: 'time'}` : le thème de
 * formulaire du projet impose le `type` du widget, l'attribut était ignoré et le champ
 * sortait en texte libre. On y tapait « 15h30 », la contrainte de format rejetait la
 * ligne, et l'écran annonçait une erreur sur la date.
 *
 * @extends AbstractType<PlanningMatchData>
 */
class PlanningMatchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('heure', TimeType::class, [
                'label' => 'Heure',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'string',
                // Sans quoi Symfony attendrait « 15:30:00 » et rendrait un champ à secondes.
                'input_format' => 'H:i',
                'with_seconds' => false,
            ])
            ->add('categorie', TextType::class, [
                'label' => 'Catégorie',
                'attr' => ['placeholder' => 'ex : Séniors, U13, Plateau U7', 'maxlength' => 60],
            ])
            ->add('adversaire', TextType::class, [
                'label' => 'Adversaire',
                'required' => false,
                'attr' => ['placeholder' => 'vide pour un plateau', 'maxlength' => 100],
            ])
            ->add('note', TextType::class, [
                'label' => 'Note',
                'required' => false,
                'attr' => ['placeholder' => 'facultatif', 'maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PlanningMatchData::class]);
    }
}
