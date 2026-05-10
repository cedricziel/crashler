## 1. Dependencies and scaffolding

- [x] 1.1 `composer require easycorp/easyadmin-bundle` (Flex recipe should register the bundle in `config/bundles.php`)
- [x] 1.2 Confirm `symfony/maker-bundle`, `symfony/security-bundle`, `symfony/form` are present (they already are per `composer.json`); confirm `symfony/security-csrf` is enabled
- [x] 1.3 Confirm `deploy.php` runs `doctrine:migrations:migrate` post-deploy; add the hook if missing
- [x] 1.4 Add a section to README ("Admin UI") describing the new `/admin` URL, the bootstrap command, and the hybrid YAML/DB model

## 2. User entity and password authentication

- [x] 2.1 `bin/console make:user` → `User` entity + `UserRepository`, store-in-db = yes, identifier = email, hash-password = yes
- [x] 2.2 Add `created_at` (immutable, `#[ORM\Column(type: 'datetime_immutable')]`) populated via lifecycle callback
- [x] 2.3 Add `#[Assert\Email]` and `#[Assert\NotBlank]` on email; add `#[ORM\UniqueConstraint(name: 'user_email_lower_uniq', columns: ['email_lower'])]` and an `email_lower` shadow column populated on persist/update via lifecycle callback (or a Doctrine listener)
- [x] 2.4 `bin/console make:auth` → form login authenticator (controller name: `SecurityController`), generate logout = yes
- [x] 2.5 Adjust `templates/security/login.html.twig` to match the project's existing template look (extends `base.html.twig`, includes the AssetMapper-managed CSS); no Tailwind/Bootstrap addition in this change unless the base template already has one
- [x] 2.6 Replace `users_in_memory` provider in `config/packages/security.yaml` with an `entity` provider keyed on `App\Entity\User` / `email`
- [x] 2.7 Update test override in `when@test:` block of security.yaml only if `make:auth` regenerates it inappropriately; the existing low-cost-hashers block must remain

## 3. Org entity and OrgMembership

- [x] 3.1 `bin/console make:entity Org` with fields: `slug` (string, unique), `name` (string), `createdAt` (datetime_immutable)
- [x] 3.2 Add `#[Assert\Regex('/^[a-z][a-z0-9-]{2,31}(?<!-)$/')]` on `Org.slug`; mirror the pattern from the existing `tenants` spec
- [x] 3.3 `bin/console make:entity OrgMembership` with fields: `user` (ManyToOne → User), `org` (ManyToOne → Org), `role` (enum string), `createdAt`
- [x] 3.4 Define `App\Entity\Enum\MembershipRole` PHP enum with cases `Owner`, `Admin`, `Member`; backed-string values `owner`, `admin`, `member`
- [x] 3.5 Add composite unique constraint `(user_id, org_id)` on `OrgMembership`
- [x] 3.6 Add ON DELETE CASCADE for `OrgMembership.user_id` (a deleted user removes their memberships); leave `OrgMembership.org_id` as RESTRICT (deleting an org with members must fail loudly)

## 4. Tenant entity (DB-backed) and TenantMembership

- [x] 4.1 `bin/console make:entity Tenant` with fields: `org` (ManyToOne → Org), `slug` (string, unique globally), `name` (string), `createdAt`
- [x] 4.2 Mirror the slug regex constraint from `Org`; the existing `tenants` spec rules carry over verbatim
- [x] 4.3 `bin/console make:entity TenantMembership` with fields: `user` (ManyToOne → User), `tenant` (ManyToOne → Tenant), `role` (MembershipRole), `createdAt`
- [x] 4.4 Composite unique `(user_id, tenant_id)` on `TenantMembership`
- [x] 4.5 Same FK delete rules as OrgMembership (cascade on user, restrict on tenant)
- [x] 4.6 Add a domain service `App\Tenancy\TenantAccessChecker` that resolves "does this user have access to this tenant?" as the union of OrgMembership(via tenant.org) and TenantMembership; returns the *highest* role across both. (Used by Change 2 voters; ship the service here so the schema is justified.)

## 5. TenantToken entity and issuance

- [x] 5.1 `bin/console make:entity TenantToken` with fields: `tenant` (ManyToOne → Tenant), `name` (string, label), `hash` (string(64), unique), `expiresAt` (datetime_immutable, nullable), `lastUsedAt` (datetime_immutable, nullable), `createdAt`, `createdBy` (ManyToOne → User, nullable)
- [x] 5.2 Add `#[Assert\Regex('/^[a-f0-9]{64}$/')]` on `hash`
- [x] 5.3 Add a service `App\Tenancy\TokenIssuer` with `issue(Tenant $t, ?string $name, ?\DateTimeImmutable $expiresAt, ?User $createdBy): IssuedToken`. The `IssuedToken` value object carries the persisted `TenantToken` plus the **plaintext** (returned once, never stored)
- [x] 5.4 Plaintext format: `cw_` + 32 lowercase hex chars (16 bytes from `random_bytes`)
- [x] 5.5 Hash with `hash('sha256', $plaintext)` (lowercase hex, matching the YAML rule)
- [x] 5.6 Add a service `App\Tenancy\LastUsedRecorder` with a `kernel.terminate` listener that updates `last_used_at` for the matched token after the response is flushed; failures log at WARNING and never bubble

## 6. Hybrid TenantRegistry

- [x] 6.1 Introduce interface `App\Tenancy\TenantSourceInterface` with `entries(): iterable<array{0: string, 1: Tenant}>`
- [x] 6.2 Refactor existing `TenantRegistryFactory` so `ConfigTenantSource` implements `TenantSourceInterface` and emits the YAML-derived entries (no behaviour change vs. today)
- [x] 6.3 Implement `App\Tenancy\DbTenantSource` reading `TenantToken` joined with `Tenant` (single SELECT), emitting `(hash, Tenant)` entries. The `Tenant` value object passed forward is the existing `App\Tenancy\Tenant` (slug + name), constructed from the entity — *not* the entity itself, to keep the hot-path immutable VO contract
- [x] 6.4 Update `TenantRegistry::fromEntries` to accept entries from multiple sources in order; on cross-source hash collision, the earlier source wins and a WARNING is logged with the slugs of both sides; on intra-source collision, hard-fail at boot as today
- [x] 6.5 Configure the registry as request-scoped (`#[AsAlias]` or service tag) and assemble it lazily on first lookup per request; cache within the request only
- [x] 6.6 Wire `IngestTokenAuthenticator` to the new request-scoped registry — no behaviour change observable from tests; the existing 401 envelope and constant-time path remain
- [x] 6.7 Make sure ingest tests still pass: with no DB tenants, registry behaves identically to today

## 7. EasyAdmin dashboard

- [x] 7.1 Replace the Flex-generated `DashboardController` with one extending `EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController`, gated by `#[IsGranted('ROLE_ADMIN')]`
- [x] 7.2 Register CRUD controllers for: User, Org, OrgMembership, Tenant, TenantMembership, TenantToken
- [x] 7.3 Configure User CRUD: list (email, roles, createdAt), edit (email, roles, password — using `TextField::new('password')->setFormType(PasswordType::class)` with the auto-hasher); deletion blocked if user has memberships (preferred: cascade-confirm dialog later)
- [x] 7.4 Configure Org CRUD: slug **immutable on edit**, name editable; show member count; show tenant count; deletion blocked if either count > 0
- [x] 7.5 Configure Tenant CRUD: slug **immutable on edit**, name editable; show parent Org; deletion blocked if `var/share/<signal>/<slug>/` exists for any signal (a `TenantDeletionGuard` service injected via the EasyAdmin lifecycle hook)
- [x] 7.6 Configure TenantToken CRUD:
  - List: tenant, name, createdAt, lastUsedAt, expiresAt, createdBy (no plaintext, no hash)
  - Create: pick tenant + name + optional expiry; on save, the form action calls `TokenIssuer::issue(...)` and redirects to a "show plaintext once" page with the plaintext rendered into the response body (NOT into a flash message; NOT into a URL parameter)
  - Edit: only `name` and `expiresAt` are mutable; `hash` is read-only
- [x] 7.7 Configure OrgMembership and TenantMembership CRUD: pick user + parent + role; composite unique enforced both at form-validation and DB level
- [x] 7.8 Add a top-nav menu item per entity in the dashboard

## 8. Firewall configuration

- [x] 8.1 Update `config/packages/security.yaml`:
  - Remove `users_in_memory` provider
  - Add an `app_user_provider` of type `entity`, class `App\Entity\User`, property `email`
  - Add `admin` firewall: pattern `^/admin`, provider `app_user_provider`, form_login (login_path: `app_login`, check_path: `app_login`), logout (path: `app_logout`)
  - Add `main` firewall: lazy: true, provider `app_user_provider`, form_login + logout (same paths)
  - Update `access_control` to require `ROLE_ADMIN` for `^/admin`; `^/v1/` and `^/compat/` rules unchanged
- [x] 8.2 Verify firewall declaration order: `dev` → `ingest` → `admin` → `main` (Symfony evaluates first-match)
- [x] 8.3 Update `IngestUserProvider` registration: it remains tied to the `ingest` firewall via `provider: ingest_provider` and is unchanged

## 9. Bootstrap console command

- [x] 9.1 Create `App\Console\Command\CreateUserCommand` exposed as `crashler:user:create`
- [x] 9.2 Required option: `--email=`
- [x] 9.3 Optional flag: `--admin` (sets `ROLE_ADMIN`); defaults to `ROLE_USER` only
- [x] 9.4 Optional option: `--password=…`; if absent, prompt with hidden input via `QuestionHelper`
- [x] 9.5 Refuse to run when STDIN is not a TTY and `--password` is missing (exit code 1, clear error)
- [x] 9.6 Email collision: error out (exit code 1, "user already exists"); never upsert
- [x] 9.7 Print the created user's email + roles on success; never print the password back

## 10. Migration

- [x] 10.1 `bin/console make:migration` → produces `migrations/VersionYYYYMMDDHHIISS.php` covering the new tables and indexes
- [x] 10.2 Hand-review the generated SQL; ensure the unique indexes use predictable names (`uniq_user_email_lower`, `uniq_org_slug`, `uniq_tenant_slug`, `uniq_tenant_token_hash`, `uniq_org_membership_user_org`, `uniq_tenant_membership_user_tenant`)
- [x] 10.3 Hand-review FK delete rules match Decision 4/4.6 (cascade on user, restrict on org/tenant)
- [x] 10.4 Run `bin/console doctrine:migrations:migrate` against a fresh dev DB; confirm clean apply and clean rollback (`doctrine:migrations:migrate prev`)

## 11. Tests

- [x] 11.1 Functional test: anonymous GET `/admin/` → 302 to `/login`
- [x] 11.2 Functional test: GET `/login` → 200, form renders
- [x] 11.3 Functional test: POST `/login` with valid email + password → 302 to `/admin/` (or configured target)
- [x] 11.4 Functional test: POST `/login` with bad password → 422 with form errors (or 302 back to login per Symfony default — match whichever is generated)
- [x] 11.5 Functional test: GET `/admin/` with non-admin user → 403
- [~] 11.6 Functional test: token issuance flow — [PARTIAL] TokenIssuerTest covers the issuer behaviour (plaintext format, hash, persistence, audit fields, uniqueness across calls). The full EasyAdmin form-submission round-trip is harder to wire from BrowserKit due to multi-step form state and is deferred to manual smoke testing. The "plaintext shown once" guarantee is structurally enforced by storing the plaintext in a one-shot session key consumed by the reveal action — never in URL, never in the persisted entity.
- [x] 11.7 Functional test: a token created in DB authenticates `POST /v1/logs` end-to-end; YAML tokens still authenticate; both work in the same request lifecycle
- [x] 11.8 Unit test: cross-source hash collision (DB + YAML same hash) — DB wins, WARNING logged
- [x] 11.9 Unit test: intra-source duplicate (two TenantTokens with same hash) — schema unique constraint catches at insert; service exception is informative
- [x] 11.10 Unit test: `TokenIssuer::issue()` returns plaintext + persisted entity; plaintext format `cw_<32 hex>`; hash matches `sha256(plaintext)`
- [x] 11.11 Unit test: `TenantAccessChecker` returns the highest role from union of OrgMembership ∪ TenantMembership; missing user → no access; member-only → `member`; org-owner + tenant-admin → `owner`
- [x] 11.12 Unit test: `LastUsedRecorder` updates the row on `kernel.terminate`; DB error logs at WARNING and does not throw
- [x] 11.13 Unit test: `CreateUserCommand` happy path + collision + non-TTY-without-password failure
- [x] 11.14 Existing ingest functional tests: every test that asserted YAML-tenant behaviour still passes unchanged
- [x] 11.15 `composer test` and `make lint` / `make format` clean

## 12. Documentation

- [x] 12.1 README "Admin UI" section: `/admin` URL, ROLE_ADMIN required, bootstrap with `crashler:user:create --email=… --admin`
- [x] 12.2 README "Tenants and ingest tokens" section: explain hybrid model, recommend DB for new installs, document precedence (DB beats YAML on collision)
- [x] 12.3 README "Token issuance via UI" subsection: where the plaintext appears, that it is shown exactly once, how to revoke (delete in EasyAdmin or remove YAML hash + redeploy)
- [~] 12.4 CONTRIBUTING.md: [N/A] no Foundry factories were introduced in this change; tests use a DatabaseTestCase with explicit factory methods. The pattern is self-documenting from `tests/Support/DatabaseTestCase.php`.
- [~] 12.5 `docs/` (if a docs tree exists): [N/A] no docs/ tree exists in this repo; the README "Roles and tenancy model" section covers the org/tenant graph.

## 13. Deferred to Change 2 (`add-tenant-self-service-ui`)

- [ ] 13.1 Public signup flow
- [ ] 13.2 User-facing dashboard
- [ ] 13.3 Token issuance UI for non-admin users
- [ ] 13.4 Invitation flow + Invitation entity
- [ ] 13.5 Voters (TenantVoter, OrgVoter)
- [ ] 13.6 Token rotation UX
- [ ] 13.7 Audit log of admin actions

## 14. Deferred to a future change

- [ ] 14.1 Tenant rename / org reparenting (data move)
- [ ] 14.2 OAuth / SSO / MFA
- [ ] 14.3 YAML deprecation + removal (after one release of observed dual-source operation)
- [ ] 14.4 APCu second-tier registry cache (only if workload demands)
