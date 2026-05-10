## ADDED Requirements

### Requirement: Public signup is gated by configuration

The system SHALL expose a public signup form at `GET /signup` and `POST /signup` only when `crashler.signup.enabled` is `true`. When `crashler.signup.enabled` is `false` (the default), all requests to `/signup` SHALL return HTTP 404 — not 403, not 401, not a redirect — so the surface is invisible to anonymous probing. The configuration SHALL be readable via the env var `CRASHLER_SIGNUP_ENABLED`.

#### Scenario: Signup hidden when disabled
- **WHEN** the application boots with `crashler.signup.enabled: false`
- **AND** an anonymous request hits `GET /signup`
- **THEN** the response status is 404
- **AND** the response body does not reveal the signup feature exists

#### Scenario: Signup form rendered when enabled
- **WHEN** the application boots with `crashler.signup.enabled: true`
- **AND** an anonymous request hits `GET /signup`
- **THEN** the response status is 200
- **AND** the form fields email + password + password confirmation are present
- **AND** if `crashler.signup.terms_url` is set, an "accept terms" checkbox is rendered linking to that URL

#### Scenario: Already-authenticated visitor redirected
- **WHEN** an authenticated user requests `/signup`
- **THEN** the response is a 302 to `/dashboard`

### Requirement: Successful signup creates a User with no memberships

The system SHALL persist a new User on successful signup with `ROLE_USER` and **zero** OrgMemberships and **zero** TenantMemberships. The new user's password SHALL be hashed by the `auto` hasher. After persist, the system SHALL log the user in (programmatically establishing the session) and redirect to `/dashboard/onboarding`.

#### Scenario: New user starts empty
- **WHEN** a successful signup creates user `bob@example.com`
- **THEN** the user has `ROLE_USER` (and only `ROLE_USER`)
- **AND** the user has zero rows in `org_membership` and zero in `tenant_membership`
- **AND** the user is logged in (session cookie set)
- **AND** the response is a 302 to `/dashboard/onboarding`

#### Scenario: Email collision rejected at signup
- **WHEN** a signup attempts to create an email that already exists (case-insensitively)
- **THEN** the form re-renders with a "this email is already registered" error
- **AND** no User row is inserted
- **AND** the existing user's password is unchanged

### Requirement: Onboarding wizard creates Org, Tenant, and first Token transactionally

The system SHALL provide `GET /dashboard/onboarding` (form) and `POST /dashboard/onboarding` (submit). The form SHALL accept Org slug+name, Tenant slug+name, and an optional first-token name (default `default`). On valid submission, the system SHALL persist — within a single database transaction — an Org, a Tenant under that Org, an OrgMembership(role=`owner`) for the current user, a TenantMembership(role=`owner`) for the current user, and a TenantToken issued via `TokenIssuer`. On any validation or persistence failure, the transaction SHALL be rolled back so no partial state is left behind. On success, the response SHALL render the new Tenant's detail page with the plaintext token visible exactly once (using the same partial as the EasyAdmin token-issuance page from Change 1).

#### Scenario: Successful onboarding
- **WHEN** an authenticated user with zero memberships POSTs `/dashboard/onboarding` with valid Org + Tenant + token name
- **THEN** the response is a 302 to `/tenants/{slug}` followed by a 200 that renders the plaintext token once
- **AND** the database now contains exactly one new Org, one new Tenant, one OrgMembership(owner), one TenantMembership(owner), and one TenantToken

#### Scenario: Validation failure leaves no partial state
- **WHEN** the onboarding POST has a valid Org slug but an invalid Tenant slug
- **THEN** the form re-renders with the Tenant slug error
- **AND** no Org, Tenant, OrgMembership, TenantMembership, or TenantToken rows are inserted

#### Scenario: User with existing memberships skips onboarding
- **WHEN** a user with at least one OrgMembership or one TenantMembership requests `/dashboard/onboarding`
- **THEN** the response is a 302 to `/dashboard`

### Requirement: Dashboard lists user's orgs and direct-invitation tenants

The system SHALL provide `GET /dashboard` requiring `ROLE_USER`. The dashboard SHALL list:
- Every Org the user is a member of (via OrgMembership), with the user's role and the count of tenants in each.
- Every Tenant the user has direct access to via TenantMembership where the parent Org is NOT in the user's OrgMembership list (the "invited collaborator" case).

The dashboard SHALL NOT N+1 — the controller SHALL pre-load all memberships in two queries (org + tenant) and pass the resulting collections to the voter so per-row authorization decisions consult an in-memory set.

#### Scenario: Dashboard shows orgs and direct tenants
- **WHEN** user `alice@example.com` has OrgMembership to `acme` and TenantMembership to `globex/prod` (where she is NOT in `globex` org)
- **AND** Alice requests `/dashboard`
- **THEN** the page lists Org `acme` with her role
- **AND** the page lists Tenant `globex/prod` under "Tenants you've been invited to"
- **AND** Tenants of `acme` (which she has access to via Org membership) are NOT listed in the "invited" section

#### Scenario: Empty dashboard redirects to onboarding
- **WHEN** an authenticated user has zero memberships AND requests `/dashboard`
- **THEN** the response is a 302 to `/dashboard/onboarding`

### Requirement: Org and Tenant management views with voter-based authorization

The system SHALL provide controllers for Org and Tenant management:

- `POST /orgs` (any `ROLE_USER`) — creates an Org; the creator becomes `owner` via OrgMembership. Slug rules from the `orgs` capability apply.
- `GET /orgs/{slug}` (granted `view` on the Org) — Org detail page.
- `POST /orgs/{slug}/memberships` (granted `manage` on the Org) — add an existing User as an org member by email.
- `DELETE /orgs/{slug}/memberships/{id}` (granted `manage`) — remove an org member.
- `POST /orgs/{slug}/tenants` (granted `create_tenant` on the Org) — create a Tenant under the Org; creator becomes Tenant `owner`.
- `GET /tenants/{slug}` (granted `view` on the Tenant) — Tenant detail page (members, tokens, last-used).
- `POST /tenants/{slug}/tokens` (granted `manage` on the Tenant) — issue a token; render plaintext once.
- `DELETE /tenants/{slug}/tokens/{id}` (granted `manage`) — revoke a token.
- `DELETE /tenants/{slug}/memberships/{id}` (granted `manage`) — remove a tenant member.

All authorization decisions SHALL go through `App\Security\Voter\OrgVoter` or `App\Security\Voter\TenantVoter`. ROLE_ADMIN SHALL bypass voters and have access to every resource.

#### Scenario: Voter rejects non-member
- **WHEN** user `eve@example.com` with no membership requests `/tenants/acme-prod`
- **THEN** the response status is 403

#### Scenario: Voter grants access to org member
- **WHEN** user `alice@example.com` with OrgMembership(role=`member`) to Org `acme` requests `/tenants/acme-prod` (a tenant under `acme`)
- **THEN** the response status is 200
- **AND** the page renders without the "manage" affordances (token issue, member edit, delete)

#### Scenario: Voter grants management to tenant admin
- **WHEN** user `bob@example.com` with TenantMembership(role=`admin`) to Tenant `acme-prod` requests `/tenants/acme-prod`
- **THEN** the page renders WITH the "manage" affordances (token issue, member edit)
- **AND** POST `/tenants/acme-prod/tokens` succeeds

#### Scenario: ROLE_ADMIN bypasses voters
- **WHEN** an installation operator with `ROLE_ADMIN` (and no Org/Tenant memberships) requests `/tenants/any-slug`
- **THEN** the response is 200 and ALL affordances are available

#### Scenario: Member cannot issue tokens
- **WHEN** user with TenantMembership(role=`member`) POSTs `/tenants/{slug}/tokens`
- **THEN** the response status is 403
- **AND** no TenantToken row is inserted

### Requirement: Invitation entity with one-time opaque tokens

The system SHALL persist invitations as `App\Entity\Invitation` rows with: `tenant_id` (FK NOT NULL), `email` (lowercased), `role` (`MembershipRole`), `token` (unique, opaque, URL-safe random ≥ 128 bits), `expires_at` (NOT NULL, default `now + crashler.invitations.expiry_days`), `created_by_user_id` (FK NOT NULL), `created_at`, `accepted_at` (nullable), `accepted_by_user_id` (FK nullable). A partial unique index SHALL prevent more than one *pending* invitation for the same `(tenant, email)` pair. On Postgres this is enforceable via a partial unique index `WHERE accepted_at IS NULL`; on Sqlite test databases the equivalent SHALL be enforced at the service level.

#### Scenario: Pending invitation uniqueness
- **WHEN** a tenant admin attempts to invite the same email to the same tenant twice while the first is still pending
- **THEN** the second creation is rejected with a "an invitation is already pending" error
- **AND** only one row exists in `invitation` for that pair

#### Scenario: Re-invite after expiry or acceptance
- **WHEN** a previous invitation to `(tenant, email)` is `accepted_at IS NOT NULL` (consumed) or `expires_at < now`
- **AND** a tenant admin issues a new invitation
- **THEN** the new invitation is accepted and persisted as a separate row

### Requirement: Invitation claim flow handles all auth states

The system SHALL provide `GET /invitations/claim/{token}` as a publicly accessible route. The controller SHALL:

- If the token is unknown, expired, or already accepted: render the appropriate dedicated error template (404 for unknown; 410 Gone for expired or already-accepted) without revealing whether the token ever existed.
- If the token is valid AND the visitor is anonymous: render a page offering both a sign-in form (with the email prefilled to the invited address) and a sign-up form (with the same email prefilled). This signup form SHALL function even when `crashler.signup.enabled` is false — the invitation token is the gate.
- If the token is valid AND the visitor is authenticated as the invited email: render a one-click "Accept invitation" page; the corresponding `POST /invitations/claim/{token}/accept` SHALL create a `TenantMembership(user, tenant, role=invitation.role)`, set `accepted_at = now`, set `accepted_by_user_id = user.id`, and redirect to `/tenants/{slug}`.
- If the token is valid AND the visitor is authenticated as a *different* email: render a "wrong account" page with a "log out and try again" link.

All claim-page responses SHALL set `Referrer-Policy: same-origin`.

#### Scenario: Anonymous visitor signs up via claim
- **WHEN** an anonymous visitor opens a valid claim URL for `bob@example.com`
- **AND** posts the signup form (with the invitation token included)
- **THEN** a User is created for `bob@example.com`
- **AND** the visitor is redirected back to the claim URL
- **AND** the next request (now authenticated) lets them accept the invitation
- **AND** signup succeeds even when `crashler.signup.enabled: false`

#### Scenario: Authenticated invited user accepts
- **WHEN** a user logged in as the invited email POSTs `/invitations/claim/{token}/accept`
- **THEN** a TenantMembership is inserted with the invitation's role
- **AND** `invitation.accepted_at` is set
- **AND** subsequent visits to the claim URL render the "already used" page

#### Scenario: Wrong account warning
- **WHEN** a user logged in as `alice@example.com` opens a claim URL invited to `bob@example.com`
- **THEN** the page explains the email mismatch
- **AND** offers a "log out and try again" affordance
- **AND** does NOT create any TenantMembership

#### Scenario: Expired invitation
- **WHEN** a visitor opens a claim URL whose `expires_at < now`
- **THEN** the response status is 410 with the "this invitation has expired" template
- **AND** no auth-state-revealing information is leaked

#### Scenario: Already-used invitation
- **WHEN** a visitor opens a claim URL whose `accepted_at IS NOT NULL`
- **THEN** the response status is 410 with the "this invitation has already been used" template

### Requirement: Inviter can revoke pending invitations

The system SHALL provide `DELETE /tenants/{slug}/invitations/{id}` (granted `manage` on the Tenant) which deletes a pending Invitation row. After deletion, the corresponding claim URL SHALL render the "unknown / expired" template (the controller SHALL not reveal the difference between revoked and never-existed). Already-accepted invitations SHALL NOT be deletable via this route — to remove a member's access after acceptance, the inviter deletes the resulting TenantMembership.

#### Scenario: Pending invitation revoked
- **WHEN** a tenant admin DELETEs `/tenants/{slug}/invitations/{id}` for a pending invitation
- **THEN** the row is removed
- **AND** subsequent visits to the claim URL return the "expired/unknown" template

#### Scenario: Accepted invitation cannot be revoked
- **WHEN** a tenant admin attempts to DELETE an invitation whose `accepted_at IS NOT NULL`
- **THEN** the response is 404 (or 410 — implementation-defined, but never 200)
- **AND** the corresponding TenantMembership remains

### Requirement: Invitation emails carry the claim URL and expire visibly

The system SHALL send an invitation email to the invitee using `Symfony\Bridge\Twig\Mime\TemplatedEmail` with both HTML and plaintext bodies. Each body SHALL contain: the inviter's display identity (their email), the tenant's display name + slug, the role being granted, the absolute claim URL `https://<host>/invitations/claim/{token}`, and the expiry date. The From address SHALL be `crashler.invitations.from_address`. Email-send failures SHALL be logged at ERROR; the controller SHALL still persist the Invitation (so the operator can manually share the claim URL on send failure) and SHALL surface a "the email could not be sent — share this link manually" notice to the inviter.

#### Scenario: Email sent on invitation creation
- **WHEN** a tenant admin creates an invitation for `bob@example.com` (mailer DSN configured)
- **THEN** exactly one email is sent
- **AND** its To address is `bob@example.com`
- **AND** its From address is `crashler.invitations.from_address`
- **AND** both HTML and plaintext bodies contain the absolute claim URL
- **AND** both bodies state the expiry date in a human-readable form

#### Scenario: Email send failure does not lose the invitation
- **WHEN** the mailer transport rejects the message
- **THEN** the Invitation row is still persisted
- **AND** the response shown to the inviter includes the claim URL and a "send failed — share manually" notice
- **AND** an ERROR log entry includes the tenant slug and the invitee's email

### Requirement: Voters delegate to TenantAccessChecker and avoid N+1

The system SHALL implement `App\Security\Voter\TenantVoter` and `App\Security\Voter\OrgVoter` as Symfony Voters. Each voter SHALL:

- Bypass to `granted` when the current token has `ROLE_ADMIN`.
- Otherwise consult `TenantAccessChecker` (or, for OrgVoter, a parallel `OrgAccessChecker`) to resolve the user's effective role on the subject.
- Map the resolved role to the requested attribute via the precedence:
  - `view`: any role
  - `manage` / `create_tenant`: `admin` or `owner`
  - `delete`: `owner` only

Controllers that list multiple subjects (e.g., the dashboard) SHALL pre-load the user's memberships once and pass the loaded collection to the voter via a request attribute (or a request-scoped service) so per-row decisions do not trigger per-row queries.

#### Scenario: TenantVoter respects role precedence
- **WHEN** a user has TenantMembership(role=`admin`) and the controller checks `IsGranted('manage', $tenant)`
- **THEN** the voter returns granted
- **WHEN** the same controller checks `IsGranted('delete', $tenant)`
- **THEN** the voter returns denied

#### Scenario: ROLE_ADMIN bypass shortcuts the access check
- **WHEN** a ROLE_ADMIN user (with no memberships) is checked for any attribute on any tenant
- **THEN** the voter returns granted
- **AND** does NOT call `TenantAccessChecker`

#### Scenario: Dashboard listing does not N+1
- **WHEN** the dashboard renders 50 tenants for a user with 50 OrgMemberships
- **THEN** the total number of database queries for membership resolution is constant (≤ 2: one for OrgMembership, one for TenantMembership) regardless of the listed count
