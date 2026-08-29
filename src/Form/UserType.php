<?php declare(strict_types=1);

namespace App\Form;

use App\Entity\RoleAcces;
use App\Entity\User;
use App\Repository\RoleAccesRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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

        // Non mappé : la collection de l'entité s'écrit par ajouterRoleAcces()/retirerRoleAcces(),
        // que l'accesseur de Symfony ne sait pas deviner. C'est UserService::remplacerRoles()
        // qui applique la sélection — un seul endroit qui touche à la collection.
        $builder->add('rolesAcces', EntityType::class, [
            'label' => 'Rôles',
            'class' => RoleAcces::class,
            'choice_label' => 'nom',
            'multiple' => true,
            'expanded' => true,
            'mapped' => false,
            'required' => false,
            'data' => $options['roles_actuels'],
            'query_builder' => static fn (RoleAccesRepository $repo) => $repo->createQueryBuilder('r')->orderBy('r.nom', 'ASC'),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true,
            'can_change_password' => true,
            'roles_actuels' => [],
        ]);

        $resolver->setAllowedTypes('can_change_password', 'bool');
        $resolver->setAllowedTypes('roles_actuels', 'array');
    }
}
