<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\Licencie;
use App\Entity\Team;
use App\Enum\PaymentMode;
use App\Repository\TeamRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LicencieEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tailleChoices = [
            'Adulte' => ['XS' => 'XS', 'S' => 'S', 'M' => 'M', 'L' => 'L', 'XL' => 'XL', 'XXL' => 'XXL'],
            'Enfant' => [
                '6 ans' => '6 ans', '8 ans' => '8 ans', '10 ans' => '10 ans',
                '12 ans' => '12 ans', '14 ans' => '14 ans', '16 ans' => '16 ans',
            ],
        ];

        $pointureChoices = [];
        foreach (range(28, 48) as $p) {
            $pointureChoices[(string) $p] = (string) $p;
        }

        $paymentChoices = [];
        foreach (PaymentMode::cases() as $mode) {
            $paymentChoices[$mode->label()] = $mode->value;
        }

        $season = $options['season'];

        $builder
            ->add('team', EntityType::class, [
                'class'         => Team::class,
                'choice_label'  => 'name',
                'label'         => 'Équipe',
                'required'      => false,
                'placeholder'   => '— Aucune équipe —',
                'query_builder' => static fn(TeamRepository $repo) => $repo->createQueryBuilder('t')
                    ->where('t.season = :season')
                    ->setParameter('season', $season)
                    ->orderBy('t.name', 'ASC'),
            ])
            ->add('tailleHaut', ChoiceType::class, [
                'label'       => 'Taille haut',
                'mapped'      => false,
                'required'    => false,
                'placeholder' => '— Non renseignée —',
                'choices'     => $tailleChoices,
                'data'        => $options['taille_haut'],
            ])
            ->add('tailleBas', ChoiceType::class, [
                'label'       => 'Taille bas',
                'mapped'      => false,
                'required'    => false,
                'placeholder' => '— Non renseignée —',
                'choices'     => $tailleChoices,
                'data'        => $options['taille_bas'],
            ])
            ->add('pointure', ChoiceType::class, [
                'label'       => 'Pointure',
                'mapped'      => false,
                'required'    => false,
                'placeholder' => '— Non renseignée —',
                'choices'     => $pointureChoices,
                'data'        => $options['pointure'],
            ])
            ->add('paymentMode', ChoiceType::class, [
                'label'       => 'Mode de paiement reçu',
                'mapped'      => false,
                'required'    => false,
                'placeholder' => '— Paiement non confirmé —',
                'choices'     => $paymentChoices,
                'data'        => $options['payment_mode']?->value,
            ])
            ->add('paymentMontant', NumberType::class, [
                'label'      => 'Montant reçu (€)',
                'mapped'     => false,
                'required'   => false,
                'scale'      => 2,
                'empty_data' => '',
                'data'       => $options['payment_montant'] !== null ? (float) $options['payment_montant'] : null,
            ])
            ->add('paymentReference', TextType::class, [
                'label'    => 'Référence',
                'mapped'   => false,
                'required' => false,
                'data'     => $options['payment_reference'],
                'attr'     => ['placeholder' => 'N° chèque, référence virement…'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'       => Licencie::class,
            'season'           => null,
            'taille_haut'      => null,
            'taille_bas'       => null,
            'pointure'         => null,
            'payment_mode'     => null,
            'payment_montant'  => null,
            'payment_reference' => null,
        ]);
    }
}
