## ADDED Requirements

### Requirement: Tenant configuration file

The system SHALL load tenant identities and authorized token hashes from a Symfony configuration file at application boot. The configuration SHALL be exposed under the `crashler.tenants` key as a map keyed by tenant slug. Each entry SHALL contain a `name` (display string) and `token_hashes` (a list of lowercase 64-character hexadecimal SHA-256 digests of valid plaintext bearer tokens). The system SHALL NOT store plaintext tokens at rest in any form. The system SHALL NOT persist tenants in a database in this change.

#### Scenario: Tenants loaded at boot
- **WHEN** the application boots with a `crashler.yaml` file containing two tenants
- **THEN** both tenants are available for authentication
- **AND** no database table is created or read for tenant data

#### Scenario: Empty tenants config rejects all requests
- **WHEN** the application boots with `crashler.tenants: {}` or the key absent
- **THEN** every authenticated request to `/v1/logs` is rejected with HTTP 401

#### Scenario: Plaintext token never persisted
- **WHEN** an operator records a token in `token_hashes`
- **THEN** the value committed to configuration is a SHA-256 hex digest, not the plaintext token
- **AND** the application has no API to retrieve or display the plaintext

### Requirement: Tenant slug rules

Each tenant slug SHALL match the pattern `^[a-z][a-z0-9-]{2,31}$` and SHALL NOT end with a hyphen. The slug SHALL be used as a filesystem path component for tenant-scoped storage. The application SHALL fail fast at boot with a clear configuration error if any configured slug violates these rules.

#### Scenario: Valid slug accepted
- **WHEN** the configuration contains a tenant slug `acme-corp`
- **THEN** the application boots successfully
- **AND** subsequent storage paths for this tenant use `acme-corp` as a directory name

#### Scenario: Invalid slug rejected at boot
- **WHEN** the configuration contains a tenant slug `Acme_Corp` or `acme-` or `ab`
- **THEN** the application fails to boot with a clear error pointing at the invalid slug
- **AND** no requests are served

### Requirement: Token hash format and uniqueness

Each entry in `token_hashes` SHALL be a string of exactly 64 lowercase hexadecimal characters. The same token hash SHALL NOT appear under two different tenants in the configuration. The application SHALL fail fast at boot with a clear error if any hash violates these rules or if a duplicate is detected.

#### Scenario: Malformed hash rejected at boot
- **WHEN** any `token_hashes` entry is shorter than 64 chars, contains uppercase or non-hex characters
- **THEN** the application fails to boot with a clear error naming the offending tenant and entry

#### Scenario: Cross-tenant duplicate rejected
- **WHEN** the same hash appears under two different tenants
- **THEN** the application fails to boot with a clear error

### Requirement: Bearer token authentication on /v1/logs

The system SHALL authenticate requests to `/v1/logs` by extracting the bearer token from the `Authorization: Bearer <token>` header, computing its SHA-256 in lowercase hex, and looking up the resulting hash in the in-memory map built from `crashler.tenants`. On a hit, the system SHALL attach the matching tenant (slug and name) to the request context for downstream handling. On a miss, on a missing or malformed `Authorization` header, the system SHALL respond with HTTP 401 and an OTLP-shaped error body.

#### Scenario: Valid token authenticates
- **WHEN** a request arrives with `Authorization: Bearer <plaintext>` whose SHA-256 hex equals a configured hash
- **THEN** the request is associated with the corresponding tenant
- **AND** request handling proceeds

#### Scenario: Missing Authorization header rejected
- **WHEN** a request arrives without an Authorization header
- **THEN** the system responds with HTTP 401
- **AND** no log records are persisted

#### Scenario: Unknown token rejected
- **WHEN** a request presents a token whose SHA-256 hex is not in the configured map
- **THEN** the system responds with HTTP 401
- **AND** no log records are persisted

#### Scenario: Malformed Authorization header rejected
- **WHEN** a request presents `Authorization: Basic ...` or `Authorization: <token>` without the `Bearer` scheme
- **THEN** the system responds with HTTP 401

### Requirement: Constant-time hash comparison

When comparing a presented token's SHA-256 against configured hashes, the system SHALL use a constant-time comparison primitive (`hash_equals` or equivalent) to mitigate timing attacks. Map lookups by hash SHALL NOT short-circuit on partial-string equality.

#### Scenario: hash_equals used for verification
- **WHEN** the system verifies a presented token
- **THEN** the comparison between computed hash and stored hash uses `hash_equals` (or an equivalent constant-time primitive)

### Requirement: No tenant management interface in v1

The system SHALL NOT expose any HTTP endpoint, console command, or other runtime interface for creating, listing, modifying, or deleting tenants or their token hashes. All tenant lifecycle operations SHALL be performed by editing the configuration file and restarting the application.

#### Scenario: No tenant management endpoints exist
- **WHEN** an operator inspects the routing table or registered console commands
- **THEN** there are no routes or commands that create, list, modify, or delete tenants or tokens
