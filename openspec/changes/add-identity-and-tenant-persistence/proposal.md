## Why

Crashler ships a deliberately DB-less data plane (Parquet on disk) and a deliberately DB-less tenant plane (`config/packages/crashler.yaml` with hand-edited SHA-256 token hashes). That YAML-only model carried the project through the OTLP ingest and read-API milestones, but it has run out of headroom for the next set of features:

- **No human users.** The only "user" abstraction in the codebase is `IngestUser` — a per-request shim built around a token-derived `Tenant`. There is no concept of a person who logs in, owns tenants, or invites others. Every operational change today is a YAML edit + redeploy.
- **No multi-tenant grouping.** Real installations have multiple teams managing multiple stacks. The mental model that has emerged in conversation is **User → Org → Tenant**: people belong to organisations, and organisations own one or more tenants (a.k.a. "stacks" in user-facing language).
- **No safe token issuance.** Today an operator computes `sha256(plaintext)` by hand, pastes the hex into YAML, and emails the plaintext out-of-band. There is no record of who issued a token, when, why, or whether it has ever been used. There is no expiry, no revocation that doesn't require a deploy.
- **No admin surface.** The `tenants` spec explicitly forbids any management interface ("SHALL NOT expose any HTTP endpoint, console command, or other runtime interface for creating, listing, modifying, or deleting tenants or their token hashes"). That guard was correct for the v1 cut; it now blocks the next milestone.

This change introduces the first persisted domain entities — `User`, `Org`, `Tenant`, `OrgMembership`, `TenantMembership`, `TenantToken` — alongside form-login authentication and an EasyAdmin-powered superadmin UI. It is **breaking** for the existing `tenants` capability: the "no DB, no management interface" requirement is removed, and the YAML configuration becomes a fallback, not the source of truth.

The user-facing self-service UI (signup, own-org dashboard, token issuance, invitations) is deliberately out of scope here. It builds on this foundation and ships in [`add-tenant-self-service-ui`](../add-tenant-self-service-ui/proposal.md).

## What Changes

### Persisted entities

- `User`: `id`, `email` (unique, citext-ish-via-lowercase), `password` (hashed by `auto` hasher), `roles` (JSON array), `created_at`. Implements `Symfony\Component\Security\Core\User\UserInterface` + `PasswordAuthenticatedUserInterface`. Generated via `bin/console make:user`.
- `Org`: `id`, `slug` (unique, same shape as `tenants` slug rule), `name`, `created_at`.
- `OrgMembership`: `id`, `user_id` (FK), `org_id` (FK), `role` (enum: `owner`, `admin`, `member`), `created_at`. Composite unique `(user_id, org_id)`.
- `Tenant`: `id`, `org_id` (FK), `slug` (unique globally — the slug remains the filesystem path component, so global uniqueness is preserved across orgs), `name`, `created_at`. Constraints from the existing `tenants` spec (slug pattern, fail-fast at boot for invalid input) carry over to validator constraints on this entity.
- `TenantMembership`: `id`, `user_id` (FK), `tenant_id` (FK), `role` (enum: `owner`, `admin`, `member`), `created_at`. Composite unique `(user_id, tenant_id)`. **Distinct from** `OrgMembership` so a user can be invited to one tenant without joining the whole org.
- `TenantToken`: `id`, `tenant_id` (FK), `name` (operator label), `hash` (lowercase 64-hex SHA-256), `expires_at` (nullable), `last_used_at` (nullable, updated on successful auth), `created_at`, `created_by_user_id` (nullable FK — null for tokens migrated from YAML or created via console). Composite unique on `hash` (globally, mirroring the YAML cross-tenant uniqueness invariant).

### Authentication

- `make:auth` form login: `LoginFormAuthenticator`, `/login` route, `/logout` route, `templates/security/login.html.twig`.
- New `admin` firewall covering `^/admin`: form login + `entity` user provider keyed by `User.email` + access control requires `ROLE_ADMIN`.
- New `main` firewall (lazy, catchall): same form login, same provider, public homepage, no global role requirement (the user-facing UI lands in Change 2).
- `ingest` firewall and `IngestTokenAuthenticator` are **unchanged in behaviour** — only the registry-loading code underneath changes. `users_in_memory` provider is removed (no longer needed).
- Console command `crashler:user:create --email=… [--admin] [--password=…]` for bootstrapping the first admin on a fresh install. Idempotent on email collision (errors out — no upsert).

### Hybrid `TenantRegistry`

- A new `DbTenantSource` reads `Tenant` + `TenantToken` rows and emits the same `(hash, Tenant)` entries `TenantRegistry::fromEntries` already consumes.
- The existing `TenantRegistryFactory` is extended (or wrapped) so the registry composes **DB entries first, YAML entries second**: on hash collision, the DB entry wins; otherwise entries are unioned. The duplicate-hash guard becomes a *cross-source* check (DB-vs-DB still hard-fails at boot; DB-vs-YAML logs a warning and prefers DB).
- The registry is rebuilt per-request (via a request-scoped service) so adding a token in EasyAdmin takes effect on the next request without a redeploy. A short in-memory cache (per-request only — no shared cache) keeps the cost of repeated lookups within a request constant.
- On successful authentication, `TenantToken.last_used_at` is updated **out-of-band** (after the response is flushed, via a Symfony event listener or a tiny terminate-handler) so the auth path remains read-only.

### Admin UI (EasyAdmin)

- `easycorp/easyadmin-bundle` added as a dependency.
- Dashboard at `/admin`, gated by `ROLE_ADMIN`, listing CRUD sections for `User`, `Org`, `Tenant`, `TenantToken`, `OrgMembership`, `TenantMembership`.
- `TenantToken` create flow:
  - Operator picks tenant + name + optional expiry.
  - Server generates a 32-byte random plaintext (`bin2hex(random_bytes(16))` style: `cw_<32 hex>`).
  - Stores SHA-256 hex, `created_by_user_id`, `created_at`.
  - **Plaintext is shown exactly once** on the create-success page, with a copy-to-clipboard affordance and a clear "this is the only time you will see this" notice.
- `Tenant` create/rename: slug is immutable after creation (changing it would invalidate filesystem paths). Rename of `name` is allowed.
- `Org` is similar: `slug` immutable after creation, `name` editable.
- All sections respect referential integrity: deleting a `Tenant` is blocked while data exists on disk for that slug (a guard service checks `var/share/<signal>/<slug>/`).

### Spec impact

- `tenants` (MODIFIED): YAML configuration becomes optional; DB becomes the primary source; the "no management interface" requirement is removed; the slug pattern, hash format, constant-time comparison, and 401 semantics are preserved verbatim.
- `identity` (NEW): User entity, password hashing rules, form-login firewalls, bootstrap command, login/logout routes.
- `orgs` (NEW): Org entity, OrgMembership, role enum, slug rules.
- `admin-ui` (NEW): EasyAdmin dashboard, ROLE_ADMIN gate, token issuance flow, slug-immutability rule.

## Capabilities

### New Capabilities

- `identity` — User entity, authentication, session firewall, bootstrap.
- `orgs` — Org entity, OrgMembership, role model.
- `admin-ui` — EasyAdmin superadmin dashboard.

### Modified Capabilities

- `tenants` — Tenants persist in the database (with YAML retained as a fallback for one transition release); a management interface now exists; tenants belong to an org; per-tenant memberships exist; tokens are first-class entities with audit metadata.

## Impact

- **Postgres becomes load-bearing for auth.** The kernel already requires Postgres (per README); ingest still works without auth-table reads (registry assembles once per request from cached rows), so a brief DB outage degrades but does not crash ingest as long as the in-process registry was warmed.
- **First real Doctrine migration.** Adds five new tables (`user`, `org`, `org_membership`, `tenant`, `tenant_membership`, `tenant_token`) plus indexes. Deploys must run `doctrine:migrations:migrate`.
- **New deps:** `easycorp/easyadmin-bundle`, `symfony/security-csrf`, `symfony/form` (already present). `make:user` / `make:auth` are dev-only; output is committed source.
- **Operational change:** existing YAML tenants keep working unchanged. New tenants/tokens added in EasyAdmin take effect immediately. Operators can migrate at their own pace; full YAML deprecation is a follow-up change once the migration is observed in production.
- **Test impact:** existing functional tests using YAML-configured tenants stay green via the YAML fallback. A new test fixture creates DB-backed tenants for the new flows.
- **Out of scope (Change 2):** public signup, user-facing dashboard, invitation flow, voters for non-admin authorization. Until Change 2 lands, only ROLE_ADMIN users (created via console) exist; everyone else is unauthenticated.
