<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Enum\MembershipRole;
use App\Entity\TenantMembership;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

final class TenantMembershipCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TenantMembership::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tenant membership')
            ->setEntityLabelInPlural('Tenant memberships')
            ->setSearchFields(['user.email', 'tenant.slug'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('user')->setRequired(true);
        yield AssociationField::new('tenant')->setRequired(true);

        if (Crud::PAGE_INDEX === $pageName || Crud::PAGE_DETAIL === $pageName) {
            yield ChoiceField::new('role')
                ->formatValue(static fn ($value): string => $value instanceof MembershipRole ? $value->value : (string) $value)
                ->renderAsBadges();
        } else {
            yield Field::new('role')
                ->setFormType(EnumType::class)
                ->setFormTypeOptions([
                    'class' => MembershipRole::class,
                    'choice_label' => static fn (MembershipRole $r): string => $r->value,
                ])
                ->setRequired(true);
        }

        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }
}
