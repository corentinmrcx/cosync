<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** @extends AbstractType<User> */
class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse email',
            'constraints' => [new NotBlank(), new Email()],
        ]);

        if ($options['can_change_password']) {
            $builder->add('plainPassword', PasswordType::class, [
                'label' => $options['is_new'] ? 'Mot de passe' : 'Nouveau mot de passe',
                'mapped' => false,
                'required' => $options['is_new'],
                'constraints' => $options['is_new']
                    ? [new NotBlank(), new Length(min: 8, minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.')]
                    : [],
                'attr' => ['placeholder' => $options['is_new'] ? '' : 'Laisser vide pour ne pas changer'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true,
            'can_change_password' => true,
        ]);

        $resolver->setAllowedTypes('can_change_password', 'bool');
    }
}
