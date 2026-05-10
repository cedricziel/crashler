<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Enum\MembershipRole;
use App\Entity\OrgMembership;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

final class OrgMembershipCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return OrgMembership::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Org membership')
            ->setEntityLabelInPlural('Org memberships')
            ->setSearchFields(['user.email', 'org.slug'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('user')->setRequired(true);
        yield AssociationField::new('org')->setRequired(true);

        if (Crud::PAGE_INDEX === $pageName || Crud::PAGE_DETAIL === $pageName) {
            // Read-only display side: show the role's string value as a badge.
            yield ChoiceField::new('role')
                ->formatValue(static fn ($value): string => $value instanceof MembershipRole ? $value->value : (string) $value)
                ->renderAsBadges();
        } else {
            // Form side: use Symfony's native EnumType so the form data
            // transformer handles backed-enum ↔ string conversion.
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
