<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Org;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class OrgCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Org::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Organisation')
            ->setEntityLabelInPlural('Organisations')
            ->setSearchFields(['slug', 'name'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        $slug = TextField::new('slug')
            ->setHelp('Lowercase, 3–32 chars, must match ^[a-z][a-z0-9-]{2,31}$ and not end with "-".');
        if (Crud::PAGE_EDIT === $pageName) {
            $slug->setDisabled(true)->setHelp('Slug is immutable after creation.');
        }
        yield $slug;

        yield TextField::new('name');
        yield IntegerField::new('tenantCount')
            ->setLabel('Tenants')
            ->onlyOnIndex()
            ->formatValue(static fn ($value, Org $org): int => $org->getTenants()->count());
        yield IntegerField::new('memberCount')
            ->setLabel('Members')
            ->onlyOnIndex()
            ->formatValue(static fn ($value, Org $org): int => $org->getMemberships()->count());
        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }

    /**
     * @param Org $entityInstance
     */
    public function deleteEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance->getTenants()->count() > 0) {
            throw new \RuntimeException(\sprintf(
                'Org "%s" still has %d tenant(s). Delete or reparent them first.',
                (string) $entityInstance->getSlug(),
                $entityInstance->getTenants()->count(),
            ));
        }
        if ($entityInstance->getMemberships()->count() > 0) {
            throw new \RuntimeException(\sprintf(
                'Org "%s" still has %d member(s). Remove memberships first.',
                (string) $entityInstance->getSlug(),
                $entityInstance->getMemberships()->count(),
            ));
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }
}
