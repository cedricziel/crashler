<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TenantToken;
use App\Entity\User;
use App\Tenancy\Token\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Token issuance is a one-shot, plaintext-visible-once flow.
 *
 * The "new" form's submission is intercepted in createNewFormBuilder/persistEntity
 * so that we never persist a TenantToken with a user-supplied hash. Instead, the
 * controller calls TokenIssuer to mint a fresh plaintext, hash it, persist the
 * row, and redirect to a `reveal` action that renders the plaintext exactly once
 * via a one-shot session flash.
 */
final class TenantTokenCrudController extends AbstractCrudController
{
    private const FLASH_KEY = 'tenant_token_plaintext_once';

    public function __construct(
        private readonly TokenIssuer $tokenIssuer,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return TenantToken::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Token')
            ->setEntityLabelInPlural('Tokens')
            ->setSearchFields(['name', 'tenant.slug'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $reveal = Action::new('reveal', 'Reveal plaintext')
            ->linkToCrudAction('reveal')
            ->displayIf(static function (TenantToken $token): bool {
                // The reveal page is only meaningful for the row that was just
                // created; it consumes a one-shot flash and is otherwise a 404.
                return false;
            });

        return $actions
            ->add(Crud::PAGE_INDEX, $reveal);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('tenant')->setRequired(true)->onlyOnForms();
        yield TextField::new('tenant.slug')
            ->setLabel('Tenant')
            ->onlyOnIndex();
        yield TextField::new('name');

        if (Crud::PAGE_EDIT === $pageName) {
            // Hash + plaintext are never editable. Show only redacted hash + audit columns.
            yield TextField::new('hashPrefix')
                ->setLabel('Hash (prefix)')
                ->setDisabled(true)
                ->formatValue(static fn ($value, TenantToken $token): string => ($token->getHashPrefix() ?? '').'…');
        } else {
            yield TextField::new('hashPrefix')
                ->setLabel('Hash')
                ->onlyOnIndex()
                ->formatValue(static fn ($value, TenantToken $token): string => ($token->getHashPrefix() ?? '').'…');
        }

        yield DateTimeField::new('expiresAt')
            ->setRequired(false)
            ->setHelp('Optional. Tokens past their expiry are rejected at authentication time.');
        yield DateTimeField::new('lastUsedAt')->onlyOnIndex();
        yield AssociationField::new('createdBy')->onlyOnIndex();
        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }

    /**
     * @param TenantToken $entityInstance
     */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenant = $entityInstance->getTenant();
        if (null === $tenant) {
            throw new \RuntimeException('TenantToken requires a tenant.');
        }

        $createdBy = $this->getUser();
        $createdByUser = $createdBy instanceof User ? $createdBy : null;

        $issued = $this->tokenIssuer->issue(
            $tenant,
            (string) $entityInstance->getName(),
            $entityInstance->getExpiresAt(),
            $createdByUser,
        );

        // Don't persist the form-bound entity (it has no hash). The issuer
        // already persisted+flushed a fresh row. Stash the plaintext in a
        // one-shot session value keyed on the row id, then redirect.
        $session = $this->getSession();
        if (null !== $session) {
            $session->set(self::FLASH_KEY.'_'.$issued->token->getId(), $issued->plaintext);
        }

        // Tell EasyAdmin to redirect to the reveal page after persist.
        $this->pendingRevealId = $issued->token->getId();
    }

    /**
     * @internal id of the just-issued token, set by persistEntity for the redirect override
     */
    private ?int $pendingRevealId = null;

    public function new(AdminContext $context): KeyValueStore|Response
    {
        $response = parent::new($context);

        if (null !== $this->pendingRevealId) {
            $url = $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction('reveal')
                ->set('entityId', $this->pendingRevealId)
                ->generateUrl();

            return $this->redirect($url);
        }

        return $response;
    }

    public function reveal(AdminContext $context): Response
    {
        $entityDto = $context->getEntity();
        if (!$entityDto->isAccessible()) {
            throw $this->createNotFoundException();
        }
        /** @var TenantToken|null $token */
        $token = $entityDto->getInstance();
        if (!$token instanceof TenantToken) {
            throw $this->createNotFoundException();
        }

        $session = $this->getSession();
        $key = self::FLASH_KEY.'_'.$token->getId();
        $plaintext = null;
        if (null !== $session && $session->has($key)) {
            $plaintext = (string) $session->get($key);
            $session->remove($key);
        }

        if (null === $plaintext) {
            // The flash has already been consumed (or this URL was bookmarked).
            // Don't reveal anything; redirect back to the index.
            $url = $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl();

            return $this->redirect($url);
        }

        return $this->render('admin/tenant_token_reveal.html.twig', [
            'token' => $token,
            'plaintext' => $plaintext,
        ]);
    }

    private function getSession(): ?SessionInterface
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();

        return $request?->getSession();
    }
}
