## 1. Configuration

- [x] 1.1 Extend `App\DependencyInjection\Configuration` to expose:
  - `crashler.signup.enabled` (bool, default false)
  - `crashler.signup.terms_url` (string, nullable)
  - `crashler.invitations.expiry_days` (int, default 7, min 1)
  - `crashler.invitations.from_address` (string, nullable; required at runtime if invitations are sent)
- [x] 1.2 Wire env vars: `CRASHLER_SIGNUP_ENABLED`, `CRASHLER_SIGNUP_TERMS_URL`, `CRASHLER_INVITATIONS_EXPIRY_DAYS`, `CRASHLER_INVITATIONS_FROM_ADDRESS`
- [x] 1.3 Confirm `MAILER_DSN` is documented in `.env`; default to `null://null` so dev does not break if mailer isn't configured

## 2. Invitation entity and migration

- [x] 2.1 `bin/console make:entity Invitation` with: `tenant` (ManyToOne → Tenant, NOT NULL), `email` (string, NOT NULL, lowercased on persist), `role` (MembershipRole), `token` (string(64), unique, NOT NULL), `expiresAt` (datetime_immutable, NOT NULL), `createdBy` (ManyToOne → User, NOT NULL), `createdAt` (datetime_immutable, NOT NULL), `acceptedAt` (datetime_immutable, nullable), `acceptedBy` (ManyToOne → User, nullable)
- [x] 2.2 Add `#[Assert\Email]` on `email`; add `#[ORM\UniqueConstraint(name: 'uniq_invitation_token', columns: ['token'])]`
- [x] 2.3 Add a partial unique index `(tenant_id, email) WHERE accepted_at IS NULL` to prevent duplicate pending invitations to the same email/tenant pair (Postgres-only via `options.where`; MariaDB falls back to service-level guard via `InvitationRepository::findPendingByTenantAndEmail`)
- [x] 2.4 `bin/console make:migration` and hand-review the SQL (especially the partial index and FK ON DELETE rules: cascade on tenant, restrict on createdBy, set null on acceptedBy)

## 3. Signup flow

- [x] 3.1 Create `App\Form\SignupType` with email, plainPassword (RepeatedType), and optional acceptTerms checkbox (rendered only if `crashler.signup.terms_url` is set)
- [x] 3.2 Create `App\Controller\SignupController` with `GET/POST /signup`:
  - If `crashler.signup.enabled` is false → throw NotFoundHttpException (404, not 403)
  - If user is already authenticated → 302 to `/dashboard`
  - On valid POST: persist User with `ROLE_USER`, hash password, log them in via `Symfony\Bundle\SecurityBundle\Security::login()`, 302 to `/dashboard/onboarding`
- [x] 3.3 Render `templates/signup/index.html.twig` extending `base.html.twig`
- [x] 3.4 Add an access-control rule allowing `^/signup` to anonymous users on the `main` firewall (PUBLIC_ACCESS)

## 4. Dashboard and onboarding

- [x] 4.1 Create `App\Controller\DashboardController`:
  - `GET /dashboard` requires `ROLE_USER`. Loads OrgMemberships and TenantMemberships for the current user. Redirects to `/dashboard/onboarding` when both are empty.
  - `GET /dashboard/onboarding` requires `ROLE_USER`. Renders the onboarding wizard form.
  - `POST /dashboard/onboarding` validates and persists Org → Tenant → first TenantToken transactionally. Persists OrgMembership(role=Owner) for the user. Persists TenantMembership(role=Owner). Redirects to `/tenants/{slug}` with the plaintext token rendered into a flash-once partial.
- [x] 4.2 Templates:
  - `templates/dashboard/index.html.twig` — list of orgs and direct-invitation tenants; empty-state link to onboarding
  - `templates/dashboard/onboarding.html.twig` — single-page wizard (Org slug+name, Tenant slug+name, first token name)
- [x] 4.3 Wire `/` → redirect to `/dashboard` if authenticated, to `/login` if anonymous (NotFoundController fallback only if both signup and login are disabled — out of scope)

## 5. Org and Tenant management views

- [x] 5.1 `App\Controller\OrgController`:
  - `GET /orgs/{slug}` — detail page; shows members, tenants, and (for owner/admin) an invite-org-member affordance and a create-tenant affordance
  - `POST /orgs` — create Org; creator becomes owner
  - `POST /orgs/{slug}/memberships` — add a user (by email) as a member; only owner/admin (no email round-trip — this is for users already on the system; invitation email flow is tenant-scoped per Decision 4)
  - `DELETE /orgs/{slug}/memberships/{id}` — remove a member; only owner/admin
- [x] 5.2 `App\Controller\TenantController`:
  - `GET /tenants/{slug}` — detail page; shows members, tokens, recent activity (last_used_at across tokens)
  - `POST /orgs/{slug}/tenants` — create Tenant under an Org; only org owner/admin; creator becomes Tenant owner via TenantMembership
  - `POST /tenants/{slug}/tokens` — issue a token; renders show-plaintext-once partial
  - `DELETE /tenants/{slug}/tokens/{id}` — revoke a token; tenant owner/admin
  - `DELETE /tenants/{slug}/memberships/{id}` — remove a tenant member; tenant owner/admin
- [x] 5.3 All actions protected by `denyAccessUnlessGranted(...)` referencing the voters from §6 (controller-level instead of `#[IsGranted]` attributes — needed to look up the entity by slug first)
- [x] 5.4 Templates extend `base.html.twig`; reuse the show-plaintext-once partial (extracted to `templates/_partials/show_plaintext_once.html.twig`)

## 6. Voters

- [x] 6.1 `App\Security\Voter\TenantVoter`:
  - Supports attributes `view`, `manage`, `delete`
  - Subject is `App\Entity\Tenant`
  - Bypass: returns granted if the user has `ROLE_ADMIN`
  - `view`: any role from `TenantAccessChecker`
  - `manage`: `admin` or `owner`
  - `delete`: `owner` only
- [x] 6.2 `App\Security\Voter\OrgVoter`:
  - Same shape, subject `App\Entity\Org`
  - `view`: any OrgMembership role
  - `manage`: `admin` or `owner`
  - `create_tenant`: `admin` or `owner`
  - `delete`: `owner` only
- [x] 6.3 Both voters delegate to `TenantAccessChecker` / direct OrgMembership lookup; injected via constructor
- [ ] 6.4 Unit tests for each voter covering every attribute × role × bypass combination

## 7. Invitation flow

- [x] 7.1 `App\Controller\InvitationController`:
  - `POST /tenants/{slug}/invitations` — create an invitation; only tenant owner/admin; sends email; returns to the tenant detail page with a "sent" flash
  - `GET /invitations/claim/{token}` — public route. Branches on auth state per Decision 6.
  - `POST /invitations/claim/{token}/accept` — authenticated + email-matched only; creates TenantMembership, sets `accepted_at` + `accepted_by_user_id`, redirects to `/tenants/{slug}`
  - `DELETE /tenants/{slug}/invitations/{id}` — revoke a pending invitation; tenant owner/admin
- [x] 7.2 `App\Mailer\InvitationMailer` service: builds and sends `TemplatedEmail` with both HTML and TXT bodies
- [x] 7.3 Templates:
  - `templates/email/invitation.html.twig` and `.txt.twig`
  - `templates/invitation/claim_anonymous.html.twig` (login-or-signup page, prefilled email)
  - `templates/invitation/claim_authenticated.html.twig` (one-click accept)
  - `templates/invitation/claim_mismatch.html.twig` (different email logged in)
  - `templates/invitation/claim_expired.html.twig`
  - `templates/invitation/claim_already_used.html.twig`
- [x] 7.4 `Referrer-Policy: same-origin` header on all `/invitations/claim/*` responses
- [x] 7.5 Allow signup from the claim page even when `crashler.signup.enabled` is false (the invitation is the gate); implementation: a dedicated invitation-claim signup endpoint, separate from `/signup`, that requires a valid invitation token to function

## 8. Firewall and access control

- [x] 8.1 Extend `config/packages/security.yaml` `access_control`:
  - `^/signup`, `^/invitations/claim/`, `^/login`, `^/logout` → PUBLIC_ACCESS
  - `^/dashboard`, `^/orgs/`, `^/tenants/` → `ROLE_USER`
  - Existing rules (`^/v1/`, `^/compat/` → `ROLE_INGEST`; `^/admin` → `ROLE_ADMIN`) unchanged
- [x] 8.2 Confirm firewall declaration order remains `dev` → `ingest` → `admin` → `main`

## 9. Stimulus and assets

- [x] 9.1 Create `assets/controllers/copy_to_clipboard_controller.js` (Stimulus); wire on the show-plaintext-once partial
- [x] 9.2 Confirm AssetMapper picks up the new controller automatically (no importmap edit needed for new files; verify with `bin/console importmap:require` if needed)

## 10. Tests

- [ ] 10.1 Functional: signup disabled → `/signup` returns 404; enabled → returns 200 and creates a user
- [ ] 10.2 Functional: signed-up user with zero memberships is redirected from `/dashboard` to `/dashboard/onboarding`
- [ ] 10.3 Functional: onboarding wizard creates Org + Tenant + Token + memberships in one transaction; on validation failure, nothing is persisted
- [ ] 10.4 Functional: tenant owner can issue a token via `/tenants/{slug}/tokens`; plaintext appears in the response once and is not in the URL
- [ ] 10.5 Functional: tenant member (non-admin) gets 403 when trying to issue a token
- [ ] 10.6 Functional: invitation flow end-to-end with `Symfony\Component\Mailer\Test\Constraint\EmailCount` assertion (1 email sent), claim URL extraction from the rendered email, anonymous claim → signup-from-claim → membership created
- [ ] 10.7 Functional: invitation claim with mismatched logged-in email → 403/UI mismatch page
- [ ] 10.8 Functional: invitation claim after `accepted_at` is set → "already used" page
- [ ] 10.9 Functional: invitation claim after `expires_at` is past → "expired" page
- [ ] 10.10 Functional: revoking a pending invitation (`DELETE /tenants/{slug}/invitations/{id}`) makes the claim URL return "invalid"
- [ ] 10.11 Functional: ROLE_ADMIN can act on any tenant/org regardless of memberships (voter bypass)
- [ ] 10.12 Functional: dashboard list view does not N+1 — pre-loaded memberships consulted by the voter (assert via Doctrine logger or query count)
- [ ] 10.13 Unit: TenantVoter and OrgVoter — every attribute × role combination + the ROLE_ADMIN bypass + missing-membership case
- [ ] 10.14 Unit: InvitationMailer renders both HTML and TXT bodies with the expected fields (claim URL, expiry, inviter name, tenant name)
- [ ] 10.15 Unit: lowercased email normalisation on Invitation persist matches User email lowercase normalisation from Change 1
- [ ] 10.16 `composer test` and `make lint` / `make format` clean

## 11. Documentation

- [ ] 11.1 README "Self-service UI" section: signup gate, dashboard URL, org/tenant management, invitation flow
- [ ] 11.2 README "Email" section: `MAILER_DSN`, recommended dev backend (Mailpit), `crashler.invitations.from_address`
- [ ] 11.3 README "Roles and access" subsection: explains role enum, OrgMembership ∪ TenantMembership semantics, ROLE_ADMIN bypass
- [ ] 11.4 `docs/` (if present): a one-page diagram of the User/Org/Tenant/Membership/Invitation graph

## 12. Deferred to a future change

- [ ] 12.1 Password reset (`/forgot-password` flow with one-time token)
- [ ] 12.2 Email verification on signup (only relevant if signup-without-invite is opened to truly public installs)
- [ ] 12.3 OAuth / SSO / SCIM / MFA
- [ ] 12.4 User self-deletion (with org-orphan handling)
- [ ] 12.5 Audit log UI
- [ ] 12.6 Token rotation UX (issue-new-with-grace-period)
- [ ] 12.7 Rate limiting on signup and invitation creation
- [ ] 12.8 Org-level invitations (extend the invitation model to optionally target an Org)
- [ ] 12.9 Tenant rename and org reparenting
