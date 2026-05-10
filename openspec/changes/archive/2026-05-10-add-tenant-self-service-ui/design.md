## Context

After `add-identity-and-tenant-persistence` lands, the Crashler installation has:

- Persisted `User`, `Org`, `Tenant`, `OrgMembership`, `TenantMembership`, `TenantToken` entities.
- A `LoginFormAuthenticator` form login at `/login`, an entity-backed user provider, and three firewalls (`ingest`, `admin`, `main`).
- An EasyAdmin dashboard at `/admin` for the installation operator (ROLE_ADMIN).
- `TokenIssuer`, `TenantAccessChecker`, `LastUsedRecorder` services.
- A "show plaintext once" Twig partial (extracted from the EasyAdmin token-issuance flow).

What's missing is the user-facing surface: anything that lets a non-admin do anything. Today (after Change 1), a tenant owner has to ask the installation operator to issue tokens, invite teammates, or even create a tenant. That's a sustainable model for an internal proof-of-concept and a deliberate non-goal of Change 1; it stops being acceptable as soon as the operator is not also the only user.

This change adds that surface as plain server-rendered HTML, with Stimulus for tiny interactions and the existing AssetMapper pipeline for assets. There is no SPA, no API for the dashboard (the existing OTLP/read API is for ingest/query, not for UI), and no separate frontend build. That choice is deliberate: keeping this UI Symfony-native means it ships, evolves, and tests like the rest of the application.

## Goals / Non-Goals

**Goals:**
- A user who runs `composer create-project` and `bin/console crashler:user:create --email=x --admin` can also (with `crashler.signup.enabled: true`) sign up an end-user account and self-serve from there.
- A signed-up user can create their first Org + Tenant + Token in one onboarding flow.
- A tenant owner can manage members and tokens for their tenants without involving the installation operator.
- A tenant owner can invite teammates by email; the recipient gets a claim link, signs up if needed, and joins the tenant.
- All authorization decisions for non-admin users go through Symfony Voters, with `TenantAccessChecker` as the source of truth for "does this user have access to this tenant."
- ROLE_ADMIN (the installation operator) can always intervene; voters bypass cleanly.

**Non-Goals:**
- Password reset / forgot-password flow. (Future change. For now: ask the installation operator, who can reset via EasyAdmin.)
- Email verification of signup (the only flow that needs proof-of-email-control is invitation; signup-without-invite is gated by the operator's `enabled` flag, so the trust comes from the operator deciding to enable it).
- OAuth, SSO, SCIM, MFA. Future changes.
- User self-deletion / GDPR export. Future change.
- Organization-level billing, plans, quotas. Crashler is self-hosted; no billing surface.
- Audit log UI. Structured logs continue to be the audit trail.
- Token rotation UX (issue-new-with-grace-period). Operators rotate by issuing a new token, switching clients, then deleting the old one.
- A separate read-only "viewer" UI for ingested data — that's the existing read API; this change does not add a log/trace browser.

## Decisions

### Decision 1: Signup is closed by default

**Choice:** `crashler.signup.enabled` defaults to `false`. When false, `/signup` returns 404 (not 403 — invisible to anyone scanning for it). The default operator install is single-tenant-from-the-CLI; opening signup is an explicit opt-in.

**Why:** Crashler is self-hosted. Most installations are operator + invited collaborators, not public SaaS. Defaulting closed protects against the spam/scrape risk by default and forces a deliberate decision when public signup is wanted.

### Decision 2: Anyone can sign up; signup creates a User with no memberships

**Choice:** A successful signup persists a User with `ROLE_USER` and *no* OrgMembership or TenantMembership. The new user's `/dashboard` is empty and prompts them to either create their first Org+Tenant or accept a pending invitation by clicking the link they were emailed.

**Alternative considered:** auto-create a personal Org for every new user. Rejected — it pollutes the schema with single-member orgs that exist only to satisfy a UI affordance. The "no orgs yet" empty state is simple to render and explicit about what's happening.

**Why:** the user explicitly drove the model "users belong to many orgs" — which means at signup time they may belong to *zero*, and that's fine.

### Decision 3: Onboarding is one wizard page, not three

**Choice:** A user who hits `/dashboard` with zero memberships is redirected to `/dashboard/onboarding`. The onboarding view is a single Twig template with three form sections submitted as one POST: Org slug+name, first Tenant slug+name, first Token name (optional, default `default`). On success, the user lands on the new Tenant's detail page with the plaintext token visible once.

**Alternative considered:** three sequential wizard steps. Rejected — three round-trips (with the risk of partial completion) for a flow that maps to three INSERTs is over-engineered for this surface.

**Why:** ship the path. We can always split the wizard later if user research demands it.

### Decision 4: Invitations are tenant-scoped, not org-scoped

**Choice:** The Invitation entity carries `tenant_id`, not `org_id`. An invitation creates a `TenantMembership`, never an `OrgMembership`. Org-level membership is granted only by org owners explicitly via the org page (a separate UI affordance, not the email flow).

**Alternative considered:** invitations can target either Org or Tenant, polymorphically. Rejected — adds schema complexity (polymorphic FK or two FK columns) for a use case the user hasn't asked for. We can add `OrgInvitation` later as its own change if needed.

**Why:** matches the user's stated model — "users can be a member of N stacks" via direct invite — without conflating it with org-level grants.

### Decision 5: Invitation token is opaque and one-time

**Choice:** `Invitation.token` is 32 random URL-safe bytes (`bin2hex(random_bytes(16))` style — `Base64Url` encoded for shorter URLs). The token is unique. On accept, the invitation row sets `accepted_at` and `accepted_by_user_id`; subsequent visits to the claim URL show "this invitation has already been used" and never re-grant access.

**Alternative considered:** signed JWTs as invitations. Rejected — JWTs would let us avoid storing one-shot state, but they trade off revocability (can't cancel a JWT mid-flight without a denylist that defeats the purpose) and the "show me pending invitations" UX (can't list JWTs the user issued). Storing the row is cheaper.

**Why:** opaque + DB-backed is the conventional shape; we get revocation, listing, and audit basically for free.

### Decision 6: The claim URL works for unauthenticated visitors

**Choice:** `/invitations/claim/{token}` is publicly reachable (no firewall denial), but the action it can take depends on authentication state:

- **Unauthenticated**: the page shows a login form prefilled with the invited email AND a signup form prefilled with the invited email. Both forms post to URLs that, on success, redirect back to the same claim URL — so the user lands on it again, this time authenticated, and can accept.
- **Authenticated as invited email**: a one-click "Accept" button creates the `TenantMembership`.
- **Authenticated as a different email**: an explanation page with a "log out and try again" link.

The signup form on this page works **even when public signup is disabled** because the invitation itself is the access gate.

**Why:** matches the UX of every modern team-invite flow. The "different email" branch is the friction point worth handling explicitly because it's the most common confusion (people are logged into the wrong account).

### Decision 7: Voters, not access lists

**Choice:** All non-admin authorization goes through Symfony Voters: `TenantVoter`, `OrgVoter`. Each voter delegates "does this user have access" to `TenantAccessChecker` (from Change 1) and "what can they do" to a small attribute table (`view`, `manage`, `delete`). Controller actions use `#[IsGranted]` attributes referencing those.

**Alternative considered:** a single `AccessChecker::can($user, $action, $resource)` service called manually in every action. Rejected — it works but ignores the framework's idiomatic seam; voters integrate with Twig's `is_granted()` helper and with API Platform later if/when this surface grows an API.

**Why:** standard Symfony, easy to extend, easy to test in isolation, easy to introspect via `bin/console debug:firewall` and similar.

### Decision 8: ROLE_ADMIN bypasses voters

**Choice:** Both voters check for `ROLE_ADMIN` first and grant access unconditionally if present. The installation operator can always intervene in any tenant or org.

**Why:** matches the existing model where ROLE_ADMIN is the all-powerful operator. There is no scenario where we want to lock the operator out of their own installation; debuggability and incident response demand the bypass.

### Decision 9: Signup without invite cannot create memberships in existing orgs/tenants

**Choice:** A user who signs up via the public form is in zero orgs and zero tenants. To get into an existing org/tenant, they need either an invitation or to be created/added by an admin. They CAN create new orgs (becoming owner) freely.

**Why:** prevents the "I signed up so now I'm in everyone's org" mistake. The onboarding wizard's "create your first Org" affordance is fine because the user becomes owner of an org they themselves created.

### Decision 10: Email rendering uses Twig + Symfony Mailer's TemplatedEmail

**Choice:** Each email has both an HTML and a plaintext template, rendered via `TemplatedEmail`. Templates are tested by asserting the rendered output contains the expected fields (claim URL, expiry, inviter name, tenant name) — not by sending real mail in tests.

**Why:** Symfony default; works with any DSN backend; easy to swap to Mailpit / null-transport in dev/test.

### Decision 11: Signed-up emails are normalized; invitations match by normalized email

**Choice:** Stored emails are lowercased on persist (Decision from Change 1). When an invitation is created, its `email` column is also lowercased. The claim flow compares the authenticated user's email and the invitation's email by lowercase equality.

**Why:** prevents the "Alice@Example.com vs alice@example.com" trap.

### Decision 12: No "create org during signup" — onboarding does it

**Choice:** The signup form takes only email + password. Org/Tenant creation happens immediately after signup in the onboarding wizard. The signup POST handler always redirects to `/dashboard/onboarding` for users with zero memberships.

**Why:** signup-form scope creep is a known pitfall; keeping it minimal makes it more debuggable and accessible. The onboarding wizard handles the "first Org" UX with proper validation.

## Risks / Trade-offs

- **Risk: signup spam when enabled.** Even though enabling signup is opt-in, an enabled signup form with no rate limiting is a vector for table-stuffing attacks. → Mitigation: out of scope for v1, but documented in the README. A future change can wire `symfony/rate-limiter` to the signup endpoint. The `enabled` flag is the primary defence.
- **Risk: invitation email goes to wrong address.** Once sent, we can't unsend. → Mitigation: the inviter-side UI shows pending invitations and offers a "revoke" action that deletes the Invitation row before the recipient claims it. Expired-by-default-7-days bounds the worst case.
- **Risk: a user signs up with email `alice@example.com`, then someone invites `Alice@Example.com` — they should match.** → Mitigation: Decision 11 normalises both sides at write time.
- **Risk: voter performance under list views.** Listing 100 tenants and calling the voter on each is 100 DB lookups via `TenantAccessChecker`. → Mitigation: dashboard queries pre-load the user's memberships once and the voter consults the loaded set, avoiding per-row queries. We pay the implementation cost in `TenantAccessChecker` to support batch resolution.
- **Risk: invitation token in URL ends up in browser history / referrer logs.** → Mitigation: the claim URL is single-use and expires; once accepted, the URL becomes invalid. Add `Referrer-Policy: same-origin` on the claim page so onward navigation does not leak the token.
- **Risk: orphaned tenants if a user creates one then abandons their account.** → Mitigation: out of scope for v1. Future "user deletion" change must address this. The installation operator can clean up via EasyAdmin.
- **Trade-off: server-rendered Twig is less flashy than an SPA.** → Acceptable. We optimise for ship-and-evolve over visual polish; the operator audience cares about correctness, not animations.

## Migration Plan

- **Roll-forward:**
  - Run the Doctrine migration adding the `invitation` table.
  - Set `MAILER_DSN` to a working SMTP (or `null://null` if invitations will not be used yet).
  - Optionally set `crashler.signup.enabled: true` if public signup is wanted.
  - No data migration: existing Users/Orgs/Tenants/Tokens from Change 1 keep working unchanged. The new dashboard simply becomes available to them.
- **Rollback:**
  - Revert the migration (drops `invitation` table; pending invitations are lost).
  - Revert the firewall/access-control changes (signup, dashboard, etc. become 404).
  - The `tenants/identity/orgs` capabilities from Change 1 are unaffected.
- **Communication:**
  - README gains a "Self-service UI" section.
  - README documents the `crashler.signup` config namespace and the mailer requirements.
  - For installation operators upgrading from Change 1: nothing breaks; existing users gain a `/dashboard` they can visit.

## Open Questions

- **Should the dashboard be at `/dashboard` or `/`?** Right now Change 1 leaves `/` undefined. Making `/dashboard` the canonical home means an unauthenticated visitor to `/` lands somewhere reasonable (login if signup disabled; signup or login otherwise). Leaning toward `/` redirecting to `/dashboard` for authenticated and to `/login` for anonymous, with `/dashboard` being the actual route.
- **Revoke-invitation UX.** Pending invitations need to be visible to the inviter and revocable. That's a small bit of UI but worth confirming where it lives — under `/tenants/{slug}` as a list under "Pending invitations"?
- **Default first-Token name.** When the onboarding wizard auto-creates a first Token, what should the default name be? "default", "onboarding", or prompt the user? Leaning prompt-with-default.
- **What does an Org with zero tenants look like?** The user can create an Org without immediately creating a Tenant under it. Probably fine; the Org page shows "no tenants yet" with a "create one" button. Worth confirming.
- **Per-tenant role escalation UX.** A tenant `member` cannot invite others; a `admin` can. How is the role displayed and edited? Probably an "Edit role" affordance on the member-list row, gated on `owner` only.
- **Do we want a dedicated "I forgot my password" path before public signup is enabled?** If signup is closed and only the operator's invitations grant accounts, password reset is a real friction point — but it's a real feature with its own threat model (token via email, expiry, single-use). Leaning: out of scope for this change; document the operator-can-reset-via-EasyAdmin workaround.
