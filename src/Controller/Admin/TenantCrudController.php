<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Tenancy\TenantDeletionGuard;
use App\Tenancy\TenantHasDataException;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class TenantCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TenantDeletionGuard $deletionGuard,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Tenant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tenant')
            ->setEntityLabelInPlural('Tenants')
            ->setSearchFields(['slug', 'name', 'org.slug'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('org')
            ->setRequired(true);

        $slug = TextField::new('slug')
            ->setHelp('Globally unique. Used as the on-disk path component under var/share/<signal>/<slug>/.');
        if (Crud::PAGE_EDIT === $pageName) {
            $slug->setDisabled(true)->setHelp('Slug is immutable after creation; it is the filesystem path.');
        }
        yield $slug;

        yield TextField::new('name');
        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }

    /**
     * @param Tenant $entityInstance
     */
    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        try {
            $this->deletionGuard->assertDeletable($entityInstance);
        } catch (TenantHasDataException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }
}
