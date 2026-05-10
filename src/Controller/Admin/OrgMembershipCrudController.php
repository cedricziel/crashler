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
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

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
        yield ChoiceField::new('role')
            ->setChoices(MembershipRole::cases())
            ->setFormTypeOption('choice_label', static fn (MembershipRole $r): string => $r->value)
            ->setFormTypeOption('choice_value', static fn (?MembershipRole $r): ?string => $r?->value)
            ->setFormTypeOption('class', MembershipRole::class)
            ->renderAsBadges()
            ->setRequired(true);
        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }
}
