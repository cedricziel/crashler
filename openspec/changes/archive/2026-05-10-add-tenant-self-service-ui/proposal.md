## Why

[`add-identity-and-tenant-persistence`](../add-identity-and-tenant-persistence/proposal.md) lays the foundation: User entities exist, Org/Tenant/Membership/Token tables exist, EasyAdmin lets the *installation operator* manage everything. But end users — the people who actually own a tenant, run an OTel collector against it, and want a teammate to share access — still have no UI. They depend on the installation operator running EasyAdmin actions on their behalf. That's tolerable for a dozen users; it doesn't scale to a real ops tool.

This change closes that gap with a hand-built (Twig + Stimulus, no SPA) self-service UI:

- **Public signup**, gated by a config flag so a self-hosted operator can disable it on a closed installation.
- A **user dashboard** showing the orgs and tenants the user has access to, with role-aware affordances.
- **Self-service tenant creation**: a signed-up user can create a new Org and a first Tenant inside it without involving the installation operator.
- **Self-service token issuance**: tenant owners and admins can issue tokens for their own tenants. The "show plaintext once" flow already exists for EasyAdmin in Change 1; this change adds the user-facing version.
- **Invitations**: tenant owners can invite teammates by email; recipients land on a one-time claim URL, sign up (or log in), and join the tenant via a `TenantMembership`.
- **Voters**: per-record authorization (`TenantVoter`, `OrgVoter`) so non-admin users can only see and act on what they own.

The capability is split out from Change 1 because it's substantial UI surface (signup form, dashboard layout, tenant CRUD views, token issuance UI, invitation flow with email + claim links, voter wiring, plus templates and Stimulus controllers) and depends on Change 1's schema being in place. Landing it separately keeps each change reviewable.

## What Changes

### Public signup

- New route `GET/POST /signup` rendered by `App\Controller\SignupController`.
- New form `App\Form\SignupType` (email, password, password confirmation, accept-terms checkbox if `crashler.signup.terms_url` is set).
- New config namespace `crashler.signup` with:
  - `enabled: bool` (default `false` — closed by default)
  - `terms_url: ?string` (optional)
- When `crashler.signup.enabled` is `false`, the route returns 404 (not 403 — the surface should be invisible to the public).
- On successful signup, the new User is auto-logged-in and redirected to `/dashboard/onboarding` (a single-page wizard that creates their first Org + first Tenant + first Token in one flow).

### User dashboard

- New route `GET /dashboard` rendered by `App\Controller\DashboardController`. Requires `ROLE_USER` (the standard role granted to every authenticated user).
- Lists the user's Orgs (from `OrgMembership`) and the user's directly-attached Tenants (from `TenantMembership` whose org is *not* in the org list — i.e., "tenants you were invited to outside your own orgs").
- Each list item shows the slug, name, role, and links to the org/tenant detail page.

### Per-Org and per-Tenant management

- `GET /orgs/{slug}` — Org detail. Shows tenants in the org, members of the org, and an "Invite member" affordance for `owner`/`admin` roles.
- `POST /orgs` — create a new Org (any authenticated user can create one; the creator becomes `owner`). Slug rules from Change 1.
- `GET /orgs/{slug}/tenants/new` + `POST /orgs/{slug}/tenants` — create a Tenant under an Org (only `owner`/`admin` of the org).
- `GET /tenants/{slug}` — Tenant detail. Shows members, tokens (without plaintext), and recent activity (last token use timestamp).
- `POST /tenants/{slug}/tokens` — issue a token; lands on a "show plaintext once" page (same template as Change 1's EasyAdmin flow, ideally extracted to a shared partial).
- `DELETE /tenants/{slug}/tokens/{id}` — revoke a token.

### Invitations

- New entity `App\Entity\Invitation`:
  - `id`, `tenant_id` (FK, NOT NULL), `email` (the invitee's email — case-insensitively normalized), `role` (`MembershipRole`), `token` (URL-safe random, opaque, unique), `expires_at` (NOT NULL, default +7 days), `created_by_user_id` (FK, NOT NULL), `created_at`, `accepted_at` (nullable), `accepted_by_user_id` (nullable FK).
- `POST /tenants/{slug}/invitations` — create an invitation; sends an email to the invitee containing the claim URL `/invitations/claim/{token}`.
- `GET /invitations/claim/{token}` — landing page that:
  - If unauthenticated: presents either a sign-in form or a sign-up form (preselecting the invited email; signup is allowed even when public signup is disabled because the invitation is the gate).
  - If authenticated as the invited email: one-click "Accept" creates the `TenantMembership` and marks the invitation accepted.
  - If authenticated as a *different* email: explains the mismatch and offers to log out.
- Invitations expire after 7 days; expired tokens render an error page with a "request another invitation" hint (no automatic resend).

### Voters

- `App\Security\Voter\TenantVoter` — supports attributes `view`, `manage` (members + tokens), `delete`. Decides via `TenantAccessChecker` (introduced in Change 1).
- `App\Security\Voter\OrgVoter` — same shape, scoped to Org actions (create tenants, invite to org-level membership).
- All controller actions in this change SHALL use `#[IsGranted]` attributes that reference these voters.
- `ROLE_ADMIN` (the EasyAdmin gate) SHALL bypass all voters — installation operators can always intervene.

### UI tooling

- All views are server-rendered Twig + Symfony AssetMapper / Stimulus (no SPA, no build step beyond what already exists).
- A small Stimulus controller for the "copy plaintext to clipboard" affordance, shared between Change 1's EasyAdmin partial and Change 2's self-service partial.
- Templates extend `templates/base.html.twig`. Styling stays consistent with whatever the project already uses (no new CSS framework added in this change).

### Mailer

- Symfony Mailer is already a dependency (`symfony/mailer`). Configure a default `MAILER_DSN` for dev (recommend `smtp://mailpit:1025` if Compose ships Mailpit; otherwise `null://null` so dev doesn't choke on missing mail).
- Email templates live under `templates/email/`. Initial templates: `invitation.html.twig` + `invitation.txt.twig` (Symfony best practice: ship both).

### Config additions

- `crashler.signup.enabled` (bool, default false)
- `crashler.signup.terms_url` (string, nullable)
- `crashler.invitations.expiry_days` (int, default 7)
- `crashler.invitations.from_address` (string, required if signup or invitations are used; the From address on outbound mail)

## Capabilities

### New Capabilities

- `tenant-self-service` — the user-facing UI: signup, dashboard, org/tenant CRUD scoped to ownership, token issuance, invitation flow, voters.

### Modified Capabilities

(none — Change 1 already lifted the management-interface restriction in the `tenants` capability)

## Impact

- **Depends on Change 1** for User, Org, Tenant, OrgMembership, TenantMembership, TenantToken, `TokenIssuer`, `TenantAccessChecker`, `last_used_at` updates, the EasyAdmin "show plaintext once" partial.
- **New table:** `invitation`. One Doctrine migration.
- **New routes:** under `^/signup`, `^/dashboard`, `^/orgs/`, `^/tenants/`, `^/invitations/`. All caught by the `main` firewall declared in Change 1; signup and invitation-claim routes are explicitly allowed for anonymous users via `access_control`.
- **Public signup risk:** off by default; operators must opt in. Even when on, signup requires email + password but does *not* automatically grant access to anything beyond the user's own newly-created Org. The installation is not exposed to spam-filling DB tables in any meaningful way.
- **Email infrastructure:** invitations require working SMTP. Documented in README; dev compose may include Mailpit.
- **No change to ingest path.** This change adds zero code to `IngestTokenAuthenticator`, the registry, or the OTLP/read paths.
- **Out of scope (future changes):** password reset (no SMTP-backed reset flow yet — operators reset via console or EasyAdmin), email verification (signup auto-verifies, since the invitation flow is the only "trust path" relying on email control), OAuth / SSO, MFA, user-deletion / GDPR export, organization billing, audit log UI, token rotation UX (rotate-with-grace-period instead of delete-and-issue).
