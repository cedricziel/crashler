# admin-ui Specification

## Purpose
TBD - created by archiving change add-identity-and-tenant-persistence. Update Purpose after archive.
## Requirements
### Requirement: EasyAdmin dashboard at /admin gated by ROLE_ADMIN

The system SHALL expose a superadmin dashboard at `/admin` powered by `easycorp/easyadmin-bundle`. Access SHALL require `ROLE_ADMIN` enforced via `access_control` on the `admin` firewall. Anonymous users SHALL be redirected to `/login`; authenticated users without `ROLE_ADMIN` SHALL receive HTTP 403. The dashboard is intended for the operator who runs the Crashler installation, not for tenants of the installation.

#### Scenario: Anonymous redirected to login
- **WHEN** an unauthenticated request hits `/admin/`
- **THEN** the response is a 302 to `/login`
- **AND** the `_target_path` query parameter (or session attribute) preserves the originally requested URL

#### Scenario: Non-admin authenticated user receives 403
- **WHEN** a user with only `ROLE_USER` requests `/admin/`
- **THEN** the response status is 403

#### Scenario: Admin user reaches the dashboard
- **WHEN** a user with `ROLE_ADMIN` requests `/admin/`
- **THEN** the response status is 200
- **AND** the dashboard renders navigation entries for User, Org, OrgMembership, Tenant, TenantMembership, TenantToken

### Requirement: CRUD on every persisted identity entity

The dashboard SHALL provide full CRUD (list, detail, create, edit, delete) for the entities `User`, `Org`, `OrgMembership`, `Tenant`, `TenantMembership`, and `TenantToken`. Edit forms SHALL respect immutability rules:
- `Org.slug` is immutable post-creation.
- `Tenant.slug` is immutable post-creation.
- `TenantToken.hash` is read-only (set automatically by `TokenIssuer` at creation; never editable).
- `User.password` is hashed on save when a non-empty value is submitted; an empty value leaves the existing hash unchanged.

#### Scenario: Slug is read-only on edit
- **WHEN** an admin opens the edit form for an existing Org or Tenant
- **THEN** the slug field is rendered as disabled (or hidden) and is ignored on form submission
- **AND** any tampered POST attempting to change the slug is rejected before flush

#### Scenario: Editing a User without changing the password
- **WHEN** an admin edits a User and submits the form with an empty password field
- **THEN** the user's existing password hash is preserved unchanged
- **AND** the user can still log in with their original password

#### Scenario: Editing a User and changing the password
- **WHEN** an admin edits a User and submits a non-empty password
- **THEN** the password is hashed by the `auto` hasher and persisted
- **AND** the user can log in with the new plaintext

### Requirement: Token issuance in EasyAdmin shows plaintext once

The dashboard SHALL implement TenantToken creation as a two-step flow:

1. Operator submits a form picking `tenant`, `name` (label), and optional `expires_at`.
2. On valid submission, `App\Tenancy\TokenIssuer::issue()` generates the plaintext, persists the hash + metadata + `created_by_user_id`, and the controller renders a "show plaintext once" template that displays the plaintext exactly once with a copy-to-clipboard affordance and a clear "this is the only time you will see this" notice.

The plaintext SHALL NEVER be stored in the session, persisted in a flash message, or placed in a URL parameter. Subsequent visits to the token's detail or edit page SHALL display the hash (or a redacted prefix) but never the plaintext.

#### Scenario: Plaintext appears in the create response, never elsewhere
- **WHEN** an admin creates a token for tenant `acme/prod` named `otel-collector`
- **THEN** the response body of the create-success page contains the plaintext exactly once
- **AND** the URL of the response does NOT contain the plaintext
- **AND** the session after the redirect does NOT contain the plaintext
- **AND** the corresponding row in `tenant_token` has `hash = sha256(plaintext)` and `created_by_user_id = <admin's id>`

#### Scenario: Edit page does not show plaintext
- **WHEN** an admin returns to the same token's edit page later
- **THEN** the page does not display the plaintext
- **AND** displays the `hash` (or a redacted form) plus the `name`, `expires_at`, `last_used_at`, `created_at`, and `created_by`

#### Scenario: Token can be revoked by deletion
- **WHEN** an admin deletes a TenantToken
- **THEN** the row is removed
- **AND** subsequent requests presenting that token receive HTTP 401 starting with the next request-assembled registry

### Requirement: Tenant deletion is blocked while data exists on disk

The dashboard's Tenant delete action SHALL invoke a `App\Tenancy\TenantDeletionGuard` service that checks whether `var/share/<signal>/<tenant-slug>/` exists for any signal (`logs`, `traces`, `metrics`). If any such directory exists, the deletion SHALL be rejected with a clear error explaining that data must be removed manually first. Force-delete is not provided in this change.

#### Scenario: Delete is rejected when data exists
- **WHEN** an admin attempts to delete Tenant `acme/prod`
- **AND** `var/share/logs/prod/` exists
- **THEN** the deletion fails with a clear error message naming the offending path
- **AND** the Tenant row remains
- **AND** all TenantTokens of the tenant remain

#### Scenario: Delete succeeds when no data exists
- **WHEN** an admin attempts to delete Tenant `acme/staging`
- **AND** no `var/share/<signal>/staging/` directory exists for any signal
- **THEN** the deletion succeeds
- **AND** all TenantTokens of the tenant are removed (FK cascade)
- **AND** all TenantMemberships of the tenant are removed (FK cascade)
- **AND** the slug `staging` becomes available for re-use

### Requirement: Org deletion is blocked while children exist

The dashboard's Org delete action SHALL be rejected if the Org has any Tenant or any OrgMembership. The administrator MUST first delete (or reparent — out of scope here) the children. This protects against accidental cascading data loss.

#### Scenario: Delete rejected with tenants present
- **WHEN** an admin attempts to delete Org `acme` while it owns at least one Tenant
- **THEN** the deletion fails with a clear error naming the count of remaining tenants

#### Scenario: Delete rejected with members present
- **WHEN** an admin attempts to delete Org `acme` while at least one OrgMembership references it
- **THEN** the deletion fails with a clear error naming the count of remaining memberships

#### Scenario: Delete succeeds when org is empty
- **WHEN** an admin attempts to delete Org `acme` with zero tenants and zero memberships
- **THEN** the deletion succeeds and the slug becomes available for re-use

