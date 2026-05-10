<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setSearchFields(['email'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield EmailField::new('email');
        yield ArrayField::new('roles')
            ->setHelp('Use ROLE_ADMIN for installation operators. Empty = ROLE_USER only.');

        $password = TextField::new('password')
            ->setFormType(RepeatedType::class)
            ->setFormTypeOptions([
                'type' => PasswordType::class,
                'first_options' => ['label' => 'New password'],
                'second_options' => ['label' => 'Confirm new password'],
                'mapped' => false,
                'required' => Crud::PAGE_NEW === $pageName,
            ])
            ->onlyOnForms();
        if (Crud::PAGE_EDIT === $pageName) {
            $password->setHelp('Leave blank to keep the existing password.');
        }
        yield $password;

        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }

    /**
     * @param User $entityInstance
     */
    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPasswordFromForm($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    /**
     * @param User $entityInstance
     */
    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPasswordFromForm($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPasswordFromForm(User $user): void
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();
        if (null === $request) {
            return;
        }

        $payload = $request->request->all();
        $formName = $request->query->get('crudControllerFqcn') ? 'User' : 'User';
        // EasyAdmin forms come in as ['User' => [...]]; the password field's
        // top-level array key matches the entity class short name.
        foreach ($payload as $key => $values) {
            if (!\is_array($values)) {
                continue;
            }
            if (isset($values['password']['first']) && '' !== $values['password']['first']) {
                $plaintext = (string) $values['password']['first'];
                $user->setPassword($this->passwordHasher->hashPassword($user, $plaintext));

                return;
            }
        }
    }
}
