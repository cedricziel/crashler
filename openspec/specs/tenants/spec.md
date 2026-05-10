## Purpose

Defines tenant identities and the bearer-token registry that authenticates inbound OTLP requests. Tenants and their token hashes are configured statically; plaintext tokens are never stored. Tenant slugs become filesystem path components for tenant-scoped storage, providing physical isolation between tenants.
## Requirements
### Requirement: Tenant configuration file

The system SHALL load tenant identities and authorized token hashes from **two sources**: the `tenant` and `tenant_token` tables (primary), and the `crashler.tenants` Symfony configuration tree (fallback). Both sources SHALL be assembled at request time into a single in-memory `TenantRegistry`. On hash collision between the two sources, the database entry SHALL win and a WARNING SHALL be logged naming both tenants. Within a single source, duplicate hashes SHALL still hard-fail at boot or at request-assembly time. The system SHALL NOT store plaintext tokens at rest in any form (hashed-only persistence applies equally to YAML and DB).

The YAML configuration is retained for one transition release so existing operators are not forced to migrate before deploying this change. A future change will deprecate and then remove the YAML source.

#### Scenario: Tenants loaded from both sources
- **WHEN** the application boots with both DB-stored tenants and a `crashler.yaml` containing additional tenants
- **THEN** authentication succeeds for any token whose SHA-256 hex matches an entry from either source

#### Scenario: DB beats YAML on hash collision
- **WHEN** the same hash is configured in YAML for tenant `legacy-acme` and stored in the DB for tenant `acme`
- **THEN** authenticating that token associates the request with the DB-stored `acme`
- **AND** a WARNING-level log entry names both slugs

#### Scenario: Empty registry rejects all requests
- **WHEN** the DB has no `tenant_token` rows AND `crashler.tenants` is empty or absent
- **THEN** every authenticated request to `/v1/logs` is rejected with HTTP 401

#### Scenario: Plaintext token never persisted
- **WHEN** a token is created via the EasyAdmin issuance flow, or recorded in YAML by hand
- **THEN** the value at rest is a SHA-256 hex digest, not the plaintext
- **AND** the application has no API to retrieve or display the plaintext after the one-time create response

### Requirement: Tenant slug rules

Each tenant slug SHALL match the pattern `^[a-z][a-z0-9-]{2,31}$` and SHALL NOT end with a hyphen. The slug SHALL be globally unique (across all orgs, since it remains the filesystem path component). The slug SHALL be immutable after creation. The application SHALL fail fast at request-assembly time (or at form-validation time) with a clear error if any slug violates these rules.

#### Scenario: Slug pattern unchanged from v1
- **WHEN** a Tenant is created with slug `acme-corp`
- **THEN** validation accepts it
- **AND** subsequent storage paths use `acme-corp` as a directory name under `var/share/<signal>/`

#### Scenario: Slug is globally unique across orgs
- **WHEN** Org `team-a` already owns a Tenant with slug `prod`
- **AND** an administrator attempts to create a Tenant with slug `prod` under Org `team-b`
- **THEN** the creation fails with a unique-constraint error

#### Scenario: Slug is immutable post-creation
- **WHEN** an administrator edits an existing Tenant
- **THEN** the slug field is read-only (or hidden) in EasyAdmin
- **AND** any attempt to change the slug via tampered POST data is rejected

### Requirement: Token hash format and uniqueness

Each token hash SHALL be a string of exactly 64 lowercase hexadecimal characters. The same token hash SHALL NOT appear under two different tenants in the *same source* (DB unique constraint on `tenant_token.hash`; configuration-time check for YAML). Cross-source duplicates are tolerated with the DB-wins precedence rule (see "Tenant configuration source").

#### Scenario: Malformed DB-stored hash rejected at form-validation
- **WHEN** an administrator attempts to insert a TenantToken with a 32-char or non-hex `hash` value (e.g., via direct DB tooling)
- **THEN** the application's request-assembly path emits a WARNING and skips the malformed row
- **AND** the validator rejects any malformed hash submitted via EasyAdmin before the row is inserted

#### Scenario: Malformed YAML hash rejected at boot
- **WHEN** a `crashler.yaml` `token_hashes` entry is shorter than 64 chars, contains uppercase or non-hex characters
- **THEN** the application fails to boot with a clear error naming the offending tenant and entry (existing behaviour preserved)

#### Scenario: Intra-DB duplicate rejected at insert
- **WHEN** an attempt is made to insert a second `tenant_token` row with a hash that already exists in the table
- **THEN** the database unique constraint rejects the insert
- **AND** the application surfaces a clear error to the administrator

#### Scenario: Intra-YAML duplicate rejected at boot
- **WHEN** the same hash appears under two different YAML tenants
- **THEN** the application fails to boot (existing behaviour preserved)

### Requirement: Bearer token authentication on /v1/logs

The system SHALL authenticate requests to `/v1/logs` (and other ingest/read paths under `/v1/` and `/compat/`) by extracting the bearer token from the `Authorization: Bearer <token>` header, computing its SHA-256 in lowercase hex, and looking up the resulting hash in the assembled `TenantRegistry` (DB + YAML). On a hit, the system SHALL attach the matching `Tenant` value object (slug + name) to the request context for downstream handling. On a miss, on a missing or malformed `Authorization` header, the system SHALL respond with HTTP 401 and an OTLP-shaped error body. The behaviour observable from outside `IngestTokenAuthenticator` SHALL be identical to the v1 implementation; only the registry assembly changes.

After successful authentication, the system SHALL update the matched `tenant_token.last_used_at` **out-of-band** (after the response is flushed via a `kernel.terminate` listener) so the auth path remains read-only. Failures of this update SHALL log at WARNING and SHALL NOT bubble to the request.

#### Scenario: Valid DB-stored token authenticates
- **WHEN** a token issued via EasyAdmin is presented in `Authorization: Bearer <plaintext>`
- **THEN** the request is associated with the corresponding Tenant
- **AND** request handling proceeds
- **AND** the matching `tenant_token.last_used_at` is updated after the response is sent

#### Scenario: Valid YAML-configured token still authenticates
- **WHEN** a token configured in `crashler.yaml` is presented
- **THEN** the request is associated with the corresponding Tenant exactly as in v1

#### Scenario: Missing Authorization header rejected (unchanged)
- **WHEN** a request arrives without an Authorization header
- **THEN** the system responds with HTTP 401

#### Scenario: Unknown token rejected (unchanged)
- **WHEN** a request presents a token whose SHA-256 hex is not in either source
- **THEN** the system responds with HTTP 401

#### Scenario: last_used_at update failure does not affect the response
- **WHEN** the post-response `last_used_at` update fails (e.g., DB connection lost)
- **THEN** the original 200/204 response is unaffected
- **AND** a WARNING is logged including the tenant slug and an opaque token hash prefix (never the full hash)

### Requirement: Constant-time hash comparison

When comparing a presented token's SHA-256 against configured hashes, the system SHALL use a constant-time comparison primitive (`hash_equals` or equivalent) to mitigate timing attacks. Map lookups by hash SHALL NOT short-circuit on partial-string equality.

#### Scenario: hash_equals used for verification
- **WHEN** the system verifies a presented token
- **THEN** the comparison between computed hash and stored hash uses `hash_equals` (or an equivalent constant-time primitive)

### Requirement: Tenant entity persisted in the database

The system SHALL persist tenants as `App\Entity\Tenant` rows in Postgres. Each Tenant SHALL belong to exactly one Org via a non-nullable `org_id` foreign key. The tenant entity SHALL carry the slug (globally unique), display name, and creation timestamp. The existing `App\Tenancy\Tenant` value object (slug + name) SHALL remain unchanged and SHALL continue to be the type passed through the ingest hot path; the entity is converted to the value object at the boundary of the registry.

#### Scenario: Tenant requires an Org
- **WHEN** an administrator attempts to create a Tenant without an Org
- **THEN** the creation fails with a non-null-constraint or validation error

#### Scenario: Hot path uses the immutable VO, not the entity
- **WHEN** `IngestTokenAuthenticator` resolves a token
- **THEN** the value attached to the security context is an `App\Tenancy\Tenant` value object (not a Doctrine entity)
- **AND** the value object's `slug` and `name` fields match the corresponding `Tenant` entity's columns at the moment of registry assembly

### Requirement: TenantMembership links users to tenants directly

The system SHALL persist `App\Entity\TenantMembership` rows linking exactly one User to exactly one Tenant with one role from the shared `MembershipRole` enum. The composite `(user_id, tenant_id)` SHALL be unique. A user MAY have a TenantMembership without having an OrgMembership to the tenant's parent org (this is the "invited collaborator" case introduced in Change 2). Deleting the User SHALL cascade-delete their tenant memberships. Deleting the Tenant SHALL be restricted while memberships exist.

Effective tenant access SHALL be the *union* of OrgMembership(via `Tenant.org`) and TenantMembership; the effective role SHALL be the maximum by precedence (`owner > admin > member`). A domain service `App\Tenancy\TenantAccessChecker` SHALL implement this resolution and SHALL be used by all authorization decisions outside the ingest hot path.

#### Scenario: Direct tenant membership grants access without org membership
- **WHEN** user `bob@example.com` has a TenantMembership(role=`member`) to Tenant `acme/prod` but no OrgMembership to Org `acme`
- **THEN** `TenantAccessChecker` returns `member` for `(bob, acme/prod)`
- **AND** Bob does not appear in the membership list of Org `acme`

#### Scenario: Org membership transitively grants access to all tenants in the org
- **WHEN** user `alice@example.com` has OrgMembership(role=`admin`) to Org `acme` and no TenantMembership to any of its tenants
- **THEN** `TenantAccessChecker` returns `admin` for every Tenant whose `org_id` is `acme`

#### Scenario: Highest role wins on union
- **WHEN** user `carol@example.com` has OrgMembership(role=`member`) to Org `acme` AND TenantMembership(role=`admin`) to Tenant `acme/prod`
- **THEN** `TenantAccessChecker` returns `admin` for `(carol, acme/prod)`

### Requirement: TenantToken entity carries audit metadata

The system SHALL persist tokens as `App\Entity\TenantToken` rows with the following columns: `id`, `tenant_id` (FK, NOT NULL), `name` (operator label, NOT NULL), `hash` (string(64), unique, NOT NULL), `expires_at` (nullable), `last_used_at` (nullable), `created_at` (NOT NULL), `created_by_user_id` (FK, nullable). Plaintext tokens SHALL be generated server-side as `cw_<32 lowercase hex chars>` (16 bytes from `random_bytes`), shown to the operator exactly once at creation, and never persisted. The hash SHALL be `lowercase(hex(sha256(plaintext)))`.

#### Scenario: Token issuance returns plaintext exactly once
- **WHEN** an administrator creates a TenantToken via EasyAdmin
- **THEN** the response page renders the plaintext value once with a clear "this is the only time you will see this" notice
- **AND** the plaintext is NOT stored in the session, in a flash message, or in any URL parameter
- **AND** subsequent visits to the token's edit page do not display the plaintext

#### Scenario: Token created via console has null createdBy
- **WHEN** a token is created via a future console command (or imported from YAML)
- **THEN** `created_by_user_id` is NULL
- **AND** the row is otherwise valid and authenticates as expected

#### Scenario: Expired tokens are rejected
- **WHEN** a request presents a token whose `expires_at` is in the past
- **THEN** the system responds with HTTP 401
- **AND** the matched row's `last_used_at` is NOT updated
- **AND** a structured log entry includes the tenant slug and "token expired"

