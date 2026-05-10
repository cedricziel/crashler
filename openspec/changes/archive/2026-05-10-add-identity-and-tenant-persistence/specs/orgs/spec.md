## ADDED Requirements

### Requirement: Org entity persisted in the database

The system SHALL persist organisations as `App\Entity\Org` rows in Postgres. Each Org SHALL have a unique slug, a display name, and a creation timestamp. An Org is a grouping unit that owns one or more Tenants.

#### Scenario: Org persisted with unique slug
- **WHEN** an administrator creates an Org with slug `acme` and name `Acme Corp`
- **THEN** a row exists in the `org` table with that slug and name
- **AND** an attempt to create a second Org with the same slug fails with a unique-constraint error

### Requirement: Org slug rules

Each Org slug SHALL match the pattern `^[a-z][a-z0-9-]{2,31}$` and SHALL NOT end with a hyphen. The slug SHALL be immutable after creation. Validation SHALL run at form-submission time (Validator constraints) and again at the database level (unique index).

#### Scenario: Invalid slug rejected at creation
- **WHEN** an administrator submits an Org with slug `Acme_Corp` or `acme-` or `ab`
- **THEN** the form validation rejects it with a clear error
- **AND** no row is inserted

#### Scenario: Slug is immutable on edit
- **WHEN** an administrator edits an existing Org via EasyAdmin
- **THEN** the slug field is rendered as read-only (or hidden)
- **AND** any attempt to change the slug via tampered POST data is rejected

### Requirement: OrgMembership links users to organisations with a role

The system SHALL persist `App\Entity\OrgMembership` rows linking exactly one User to exactly one Org with one role from the enum `{owner, admin, member}`. The composite `(user_id, org_id)` SHALL be unique. Deleting the User SHALL cascade-delete their memberships. Deleting the Org SHALL be restricted while memberships exist.

#### Scenario: A user can be a member of multiple orgs
- **WHEN** user `alice@example.com` is added as `admin` to Org `acme` and as `member` to Org `globex`
- **THEN** two rows exist in `org_membership` for that user
- **AND** loading the user's memberships returns both

#### Scenario: A user cannot have two memberships in the same org
- **WHEN** an attempt is made to insert a second OrgMembership for the same `(user, org)` pair
- **THEN** the insert fails with a unique-constraint error

#### Scenario: Deleting a user removes their memberships
- **WHEN** user `alice@example.com` is deleted while having OrgMemberships
- **THEN** all rows in `org_membership` for that user are deleted by the database
- **AND** the org rows themselves remain intact

#### Scenario: Deleting an org with memberships is rejected
- **WHEN** an administrator attempts to delete Org `acme` while at least one OrgMembership references it
- **THEN** the deletion fails with a clear error
- **AND** the org and all memberships remain

### Requirement: Membership role enum is shared between OrgMembership and TenantMembership

The system SHALL define a single PHP enum `App\Entity\Enum\MembershipRole` with cases `Owner`, `Admin`, `Member` (string-backed values `owner`, `admin`, `member`). Both `OrgMembership.role` and `TenantMembership.role` SHALL reference this enum. Role precedence is `owner > admin > member`.

#### Scenario: Role values are persisted as strings
- **WHEN** an OrgMembership is persisted with role `MembershipRole::Owner`
- **THEN** the database row stores the string `owner`

#### Scenario: Role precedence ordering
- **WHEN** application code compares roles
- **THEN** `owner` is treated as higher than `admin`, which is higher than `member`
