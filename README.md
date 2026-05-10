# Crashler

A self-hosted log/error sink that receives OpenTelemetry logs over OTLP/HTTP-JSON and stores them as Hive-partitioned Parquet files on local disk.

## Status

In active development. The first feature — OTLP/HTTP-JSON log ingest with multi-tenant authentication and per-request Parquet writes — is implemented under [`openspec/changes/add-otlp-log-ingest/`](openspec/changes/add-otlp-log-ingest/).

## Requirements

- PHP 8.5 with `ext-ctype`, `ext-iconv`
- Composer 2
- Postgres 16 (used by Symfony / Doctrine; the log ingest path itself does not write to the DB in v1)

### Optional PHP extensions

- `ext-zstd` — required if `CRASHLER_PARQUET_COMPRESSION=ZSTD`
- `ext-snappy` — recommended for `SNAPPY` compression (works in pure PHP without it but is materially slower)
- `ext-brotli`, `ext-lz4` — required for the corresponding compression codecs
- `pcov` (or `xdebug`) — required for `composer test:coverage`. PCOV is the recommended choice for speed.

## Quality stack

Three quality tools live under `tools/<tool>/` with their own isolated `composer.json` and `composer.lock` so their dependencies never collide with the main app's. The main `composer.json` does not carry them as require-dev — production installs (`composer install --no-dev`) skip them entirely.

| Tool          | Path                | Purpose                          |
|---------------|---------------------|----------------------------------|
| PHPStan       | `tools/phpstan/`    | Static analysis at level 6       |
| PHP-CS-Fixer  | `tools/php-cs-fixer/` | Code-style enforcement (Symfony preset) |
| Rector       | `tools/rector/`     | On-demand automated refactors    |

### One-time setup

```bash
composer tools:install                     # populate tools/<tool>/vendor/
git config core.hooksPath .githooks        # opt in to the pre-commit hook
```

The pre-commit hook runs PHP-CS-Fixer on staged `.php` files (auto-fix + re-stage) then PHPStan against the whole project. Aborts the commit on PHPStan failures. Bypass once with `git commit --no-verify` if you really need to land something despite findings — CI runs the same checks via `composer quality` and won't merge if they fail.

### Day-to-day commands

```bash
composer quality            # cs:check + phpstan, exits non-zero on any finding
composer cs:check           # what php-cs-fixer would change (no-op)
composer cs:fix             # actually apply the changes
composer phpstan            # static analysis
composer rector:dry         # preview Rector's suggestions
composer rector             # apply Rector (don't do this casually)
composer tools:update       # bump tool versions and refresh per-tool lockfiles
```

The PHPStan baseline at `phpstan-baseline.neon` captures pre-existing findings while the codebase catches up. New code should not add to it; the baseline file is regenerated with `composer phpstan -- --generate-baseline` and entries are removed as code is fixed.

## Configuration

### Tenants and ingest tokens

Tenants and ingest tokens live in the **database** (`tenant` and `tenant_token` tables) and are managed via the admin UI at `/admin` or the user-facing tenant page at `/tenants/<slug>`. Each tenant belongs to exactly one Org; a user's effective access to a tenant is the union of their `OrgMembership` (via the tenant's parent org) and any direct `TenantMembership`. Earlier releases supported a YAML configuration tree (`crashler.tenants`) as a fallback alongside the database; that path has been removed once the migration helper finished its work.

Issuing a token generates the plaintext server-side, shows it exactly once with a copy-to-clipboard button, and stores only its SHA-256 hash plus audit metadata (creator, created-at, last-used-at, optional expiry).

#### Issuing tokens

After bootstrapping an admin (see "Admin UI" below):

1. Sign in at `/login`.
2. Navigate to **Tokens → Add new** under `/admin`, or open the tenant page at `/tenants/<slug>` and click "+ Issue token".
3. Pick the parent tenant, give the token a label, and (optionally) set an expiry.
4. The next page renders the plaintext exactly once. **Copy it now** — only the SHA-256 hash is stored, so a lost plaintext means re-issuing.

Revoke a token by deleting the row from either UI surface. The change takes effect on the next request — no redeploy needed.

### Self-service UI

End users get their own surface, separate from the operator-only `/admin` panel:

- `/signup` — public account creation. **Closed by default** (`CRASHLER_SIGNUP_ENABLED=0`). When closed, the URL returns 404 (not 403) so it's invisible to anonymous probing. Set `CRASHLER_SIGNUP_ENABLED=1` to open it; optionally set `CRASHLER_SIGNUP_TERMS_URL` to render an "I accept the terms" checkbox linking to your TOS.
- `/dashboard` — landing page after sign-in; lists the user's orgs and any tenants they were invited to outside their own orgs. Empty memberships → redirects to onboarding.
- `/dashboard/onboarding` — single-page wizard that creates an Org + first Tenant + first Token in one transaction. New signups land here.
- `/orgs/{slug}` — org detail, tenant list, member management. `manage` and `create_tenant` actions gated to org `owner`/`admin`.
- `/tenants/{slug}` — tenant detail, members, tokens, pending invitations. `manage` actions gated to tenant `owner`/`admin`.

Authorization for these pages goes through `App\Security\Voter\TenantVoter` and `App\Security\Voter\OrgVoter`. Effective access is the union of `OrgMembership` (via the tenant's parent org) and `TenantMembership`; the highest role wins. `ROLE_ADMIN` (the installation operator) bypasses voters and can act on every resource.

#### Invitations

Tenant owners and admins issue invitations from the tenant page. Each invitation:

- generates an opaque single-use claim URL `https://<host>/invitations/claim/<token>`
- sends an HTML+plaintext email to the invitee (requires `MAILER_DSN` and `CRASHLER_INVITATIONS_FROM_ADDRESS` to be set; see "Email" below)
- expires in `CRASHLER_INVITATIONS_EXPIRY_DAYS` days (default 7)
- can be revoked by the inviter as long as it hasn't been accepted

Claim flow:

- **Anonymous visitor** → claim page shows both a sign-in form (prefilled email) and a sign-up form (also prefilled). The sign-up form works **even when public signup is closed**, because the invitation token is the gate.
- **Authenticated as the invited email** → one-click "Accept" creates a `TenantMembership` and bounces to the tenant page.
- **Authenticated as a different email** → mismatch page with a "log out and try again" link.
- **Expired or already-used** → 410 with a clear message.

All claim responses set `Referrer-Policy: same-origin` so the token doesn't leak to onward navigation.

### Email

Invitation email needs Symfony Mailer working. Set `MAILER_DSN` to a real transport in production. In dev, the project's `compose.override.yaml` ships [Mailpit](https://github.com/axllent/mailpit) — point `MAILER_DSN=smtp://localhost:1025` at it for browsable received-mail inspection at `http://localhost:8025`. In test, `null://null` is the default and the test suite does not actually dispatch.

`CRASHLER_INVITATIONS_FROM_ADDRESS` is the From address on outbound mail. Required only when an inviter actually creates an invitation; the kernel boots fine without it. If a send fails (DSN unreachable, etc.), the invitation row stays persisted and the inviter sees a "share this link manually" notice with the claim URL — so a transient mail outage doesn't lose the invitation.

### Admin UI

`/admin` is the operator dashboard, gated by `ROLE_ADMIN` and powered by EasyAdmin. From here you can manage Users, Organisations, Tenants, Tokens, and per-org / per-tenant memberships.

#### Bootstrapping the first admin

On a fresh install, run:

```bash
bin/console crashler:user:create --email=admin@example.com --admin
```

The command prompts for a password (hidden) when stdin is a TTY; pass `--password=...` for non-interactive setups. Email collisions are an error, never an upsert. Subsequent users can be created the same way (with or without `--admin`).

#### Roles and tenancy model

- `User` — has an email (case-insensitively unique) and a hashed password.
- `Org` — a grouping unit; one org owns one or more tenants.
- `Tenant` — has a slug (globally unique, immutable, used as the on-disk path component under `var/share/<signal>/<slug>/`) and belongs to exactly one org.
- `OrgMembership` — links a user to an org with a role (`owner | admin | member`).
- `TenantMembership` — links a user directly to a tenant (e.g. an "invited collaborator" who is not in the parent org).

Effective tenant access for a user is the **union** of org-level and tenant-level memberships, and the effective role is the highest by precedence (`owner > admin > member`). The user-facing self-service UI (signup, dashboard, invitations) is a follow-up change; for now everything is operator-only via `/admin`.

#### Deletion guards

- A tenant cannot be deleted while data exists at `var/share/<signal>/<slug>/` for any signal — this protects against accidental data loss.
- An org cannot be deleted while it owns tenants or has memberships.
- The slug of an existing org or tenant is immutable; renaming requires a future "tenant rename" feature.

### Storage root

`APP_SHARE_DIR` (default `var/share`) is the root of the Parquet file tree. The kernel fails to boot if the directory does not exist or is not writable.

### Compression

`CRASHLER_PARQUET_COMPRESSION` selects the Parquet codec (default `GZIP`). See `.env` for the full list and extension requirements.

## On-disk layout

Each successful OTLP write produces exactly one Parquet file under the tenant's signal subdirectory:

```
$APP_SHARE_DIR/<signal>/
  <tenant-slug>/
    date=YYYY-MM-DD/
      hour=HH/
        part-<ulid>.parquet
```

`<signal>` is `logs` for `POST /v1/logs` and `traces` for `POST /v1/traces`. `<tenant-slug>` is the authenticated tenant's slug. Date and hour are derived from the request's wall-clock arrival time in UTC. Per-record event timestamps are preserved inside the file (logs: `time_unix_nano`; traces: `start_time_unix_nano`).

## Schemas and column conventions

Each signal's Parquet layout is declared in a versioned YAML at `config/schemas/<signal>/v<n>.yaml`. Today: [`logs/v1`](config/schemas/logs/v1.yaml) (one row per `LogRecord`), [`traces/v1`](config/schemas/traces/v1.yaml) (one row per `Span`, with `events` and `links` carried as JSON-string columns), and [`metrics/v1`](config/schemas/metrics/v1.yaml) (one row per **data-point**, with a `metric_type` discriminator and JSON-string columns for histogram buckets, exponential-histogram detail, summary quantiles, and exemplars). The YAML lists every column (name, type, repetition), the OpenTelemetry semantic-convention promotions that turn well-known attributes into top-level columns, and a reserved `transforms:` block for future ingest-time mutations.

Promoted column names follow a three-level prefix convention:

```
resource_*        from ResourceLogs.resource.attributes
                  e.g. resource_service_name, resource_host_name,
                  resource_deployment_environment

scope_*           from ScopeLogs.scope (top-level fields and, eventually,
                  scope.attributes)
                  e.g. scope_name, scope_version, scope_schema_url

<unprefixed>      record-level fields and per-record promoted attributes
                  e.g. severity_text, body_json, time_unix_nano,
                  event_name, exception_type
```

Promoted columns are **shadows**: every promoted attribute also stays in `resource_attributes_json` / `attributes_json` blobs unchanged. Renaming or removing a column is a YAML edit (with a version bump for breaking changes); the JSON blob is the lossless source of truth.

Two infrastructure columns are appended by the writer to every file regardless of signal:

- `_schema_version` (int32) — copied from the YAML's `version`.
- `_schema_id` (string) — `<signal>/v<version>`, e.g. `logs/v1`.

The on-disk Parquet schema is treated as **internal**. The HTTP read API (below) is the public read contract; the DuckDB recipes further down are operator/debug tooling, not a stable public interface.

## Reading data

Crashler exposes an HTTP read API alongside the OTLP write endpoints. Same Bearer token grants access to read what you wrote.

### Endpoints

| Path                          | Verb | Returns                                                                  |
| ----------------------------- | ---- | ------------------------------------------------------------------------ |
| `/v1/logs`                    | GET  | search log records, paginated                                            |
| `/v1/traces`                  | GET  | search spans, paginated                                                  |
| `/v1/traces/{traceId}`        | GET  | one full span tree, OTLP `ResourceSpans`-shaped (32 lowercase hex chars) |
| `/v1/spans/{spanId}`          | GET  | one span, OTLP `Span`-shaped (16 lowercase hex chars)                    |
| `/v1/metrics`                 | GET  | search metric data-points, paginated                                     |
| `/docs.jsonopenapi`           | GET  | auto-generated OpenAPI 3 spec (anonymous)                                |
| `/docs`                       | GET  | Swagger UI for browsable exploration (anonymous)                         |

Auth is the same Bearer token used for OTLP write — no separate read tokens. Add `Authorization: Bearer <plaintext-token>` to every request.

### Time window

Every search REQUIRES a time window. Default is the last 1 hour; you can override with:

- `since=<RFC3339>` + `until=<RFC3339>` (both absolute), or
- `since=<unix-nano>` + `until=<unix-nano>`, or
- `since=2h` (or `30m`, `7d`) — duration shorthand implies `until=<now>`.

Mixing `since=<duration>` with an absolute `until` is rejected. Window > 30 days (configurable via `CRASHLER_READ_MAX_TIME_WINDOW_DAYS`) is rejected. The window prunes which `date=…/hour=…` Hive partitions get scanned, so narrow windows are fast.

### Common filters (all signals)

- `service` — exact match on `resource_service_name`
- `environment` — exact match on `resource_deployment_environment`
- `host` — exact match on `resource_host_name`
- `limit` — page size (default 100, max 1000)
- `cursor` — opaque pagination token from a previous response

When `cursor` is supplied, every other criterion is ignored — the cursor encodes the original criteria.

### Per-signal filters

```
/v1/logs    severityNumber severityNumberMin severityText
            traceId spanId eventName bodyContains
/v1/traces  name (with optional leading or trailing *) kind statusCode
            httpStatusCodeMin traceId parentSpanId
/v1/metrics metricName metricType aggregationTemporality exemplarTraceId
```

Every signal also accepts `attribute.<key>=<value>` filters that match against the per-row `attributes_json` column. Multiple distinct keys compose with logical AND; the per-request cap is `crashler.read.max_attribute_filters` (default 5, env `CRASHLER_READ_MAX_ATTRIBUTE_FILTERS`). Repeating the *same* attribute key (`?attribute.k=a&attribute.k=b`) returns 400 — that's a "repeated query parameter" violation. For OR-of-values on a key, use `POST /v1/<signal>/search` (below).

Unknown query parameters → 400. Enum mismatches (`kind`, `metricType`, etc.) → 422 (API Platform's parameter validation kicks in first; semantic violations like `metricName=http.*` wildcards return 400).

### Wire formats

Search responses are content-negotiated based on the `Accept` header:

- `application/ld+json` — Hydra (default; typed, discoverable)
- `application/hal+json` — HAL with `_links` blocks
- `application/json` — compact (jq-friendly)
- `application/vnd.api+json` — JSON:API

Same data, different shapes. The trace-by-id and span-by-id endpoints always return the OTLP shape they're designed for, with a `_links` block alongside.

### Examples

```bash
TOKEN="<your-tenant-token>"

# Recent errors, compact JSON
curl -H "Authorization: Bearer $TOKEN" \
     -H "Accept: application/json" \
     "https://crashler.example.com/v1/logs?service=checkout&severityNumberMin=17&since=1h"

# Full trace tree by ID
curl -H "Authorization: Bearer $TOKEN" \
     "https://crashler.example.com/v1/traces/5b8aa5a2d2c872e8321cf37308d69df2"

# Histograms for a service in the last 24h
curl -H "Authorization: Bearer $TOKEN" \
     "https://crashler.example.com/v1/metrics?service=checkout&metricType=HISTOGRAM&since=24h"
```

### Performance: row-group push-down

The streaming scanner reads each Parquet file's row-group metadata (via flow-php's `ParquetFile::metadata()->rowGroups()`) before opening any data pages. For every active numeric predicate it compares the predicate's accepted bounds against the per-row-group `min`/`max` statistics; row groups whose `[min, max]` interval is provably disjoint from the predicate are skipped entirely — their data pages are never decompressed.

Filters that benefit from push-down (numeric column with stats):

- `since` / `until` — bounds on `time_unix_nano` / `start_time_unix_nano`
- `severityNumberMin` (logs)
- `severityNumber` (logs)
- `httpStatusCodeMin` (traces)

Filters that do NOT push down — combine them with at least one filter from the list above (or a tight time window) for selectivity:

- `bodyContains` — substring scan over `body_json`
- `attribute.<key>=<value>` — decoded JSON walk over `attributes_json`
- `metricName`, `service`, `environment`, `host`, `kind`, `statusCode` — string predicates (string-stats push-down deferred)

The scanner's `ScanResult` exposes `groupsScanned` and `groupsSkipped` counters for tests and structured logging; they are NOT surfaced in the HTTP response body.

### POST /v1/&lt;signal&gt;/search — complex criteria

The GET search endpoints take URL-shaped filters that compose with AND only. For OR, NOT, IN-lists, and nested predicate trees there's a sibling `POST /v1/<signal>/search` endpoint per signal that takes the same time window and filter set as a JSON body, plus a small predicate-tree DSL.

Body shape:

```json
{
  "since": "1h",
  "until": null,
  "limit": 100,
  "cursor": null,
  "criteria": { /* predicate tree */ }
}
```

Predicate tree leaves and combinators:

- `{"all": [<node>, …]}` — AND
- `{"any": [<node>, …]}` — OR
- `{"not": <node>}` — NOT (single child)
- `{"column": "<col>", "op": "<op>", "value": <v>}` — typed-column leaf. `<op>` ∈ `eq`, `ne`, `gte`, `lte`, `prefix`, `suffix`, `in`. `value` is `<v>` for non-`in` ops, an array for `in`.
- `{"attribute": "<key>", "op": "eq", "value": "<v>"}` — decoded attribute walk against `attributes_json`. Multiple distinct keys compose with logical AND up to a per-request cap (default 5).
- `{"body": "contains", "value": "<substring>"}` — body substring match (logs only).

Examples:

```bash
TOKEN="<your-tenant-token>"

# Logs: errors from {checkout, payments} matching "panic" in the body
curl -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -X POST "https://crashler.example.com/v1/logs/search" \
     -d '{
       "since": "1h",
       "criteria": {
         "all": [
           {"any": [
             {"column": "resource_service_name", "op": "eq", "value": "checkout"},
             {"column": "resource_service_name", "op": "eq", "value": "payments"}
           ]},
           {"column": "severity_number", "op": "gte", "value": 17},
           {"body": "contains", "value": "panic"}
         ]
       }
     }'

# Traces: SERVER spans on /orders/* whose status is not OK
curl -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -X POST "https://crashler.example.com/v1/traces/search" \
     -d '{
       "since": "1h",
       "criteria": {
         "all": [
           {"column": "kind_text", "op": "eq", "value": "SERVER"},
           {"column": "name", "op": "prefix", "value": "GET /orders/"},
           {"not": {"column": "status_text", "op": "eq", "value": "OK"}}
         ]
       }
     }'

# Metrics: a list of metric names by exact match
curl -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -X POST "https://crashler.example.com/v1/metrics/search" \
     -d '{
       "since": "1h",
       "criteria": {
         "column": "metric_name",
         "op": "in",
         "value": ["http.server.request.duration", "http.client.request.duration"]
       }
     }'
```

Limits:
- Body: ≤ 64 KiB (`CRASHLER_READ_POST_SEARCH_MAX_BODY_BYTES`).
- Tree depth: ≤ 8 levels of `all` / `any` / `not` nesting.
- `in` list: ≤ 256 values per leaf.
- Distinct `attribute` keys: ≤ 5 per request.

Cursor pagination on POST search: the response carries a top-level `cursor` field when more rows exist. Clients echo the value verbatim into the next request body's `cursor` field. Cursors are bound to both the HTTP method (POST) and the criteria tree they were minted with — mutating the criteria between pages or replaying a GET cursor against POST returns 400. The `since`/`until` window is captured in the cursor, so subsequent POSTs with the same cursor see the same absolute window.

### Aggregations

`GET /v1/<signal>/aggregate` rolls up matching rows into a function value (count, sum, avg, min, max), optionally grouped by a typed column. It reuses the same filter set as `GET /v1/<signal>` plus four aggregation-specific parameters:

- `function` (required) — `count` | `sum` | `avg` | `min` | `max`. Percentile functions (`p50` / `p90` / `p95` / `p99`) are tracked under a follow-up.
- `column` (required for non-`count`) — names the numeric column whose values feed the accumulator. Per-signal allow-lists:
  - logs: `severityNumber`
  - traces: `httpResponseStatusCode`, `durationNano`
  - metrics: `valueDouble`, `valueInt`, `count`, `sum`
- `groupBy` (optional) — single typed column from a per-signal allow-list:
  - logs: `service`, `environment`, `host`, `severityText`, `severityNumber`, `eventName`
  - traces: `service`, `environment`, `host`, `kind`, `statusCode`, `name`
  - metrics: `service`, `environment`, `host`, `metricName`, `metricType`, `aggregationTemporality`
- `interval` — DEFERRED in v1. Requests supplying `interval` return HTTP 501.

Multi-column `groupBy` is not supported in v1; a request carrying a comma in `groupBy` returns 400.

```bash
TOKEN="<your-tenant-token>"

# Total error count in the last hour for the checkout service
curl -H "Authorization: Bearer $TOKEN" \
     "https://crashler.example.com/v1/logs/aggregate?since=1h&service=checkout&function=count"

# Error count grouped by service over the last 6 hours
curl -H "Authorization: Bearer $TOKEN" \
     "https://crashler.example.com/v1/logs/aggregate?since=6h&function=count&groupBy=service"

# Sum of severityNumber per service (degenerate for logs but illustrates non-count usage)
curl -H "Authorization: Bearer $TOKEN" \
     "https://crashler.example.com/v1/logs/aggregate?since=1h&function=sum&column=severityNumber&groupBy=service"
```

The response is a flat JSON document:

```json
{
  "function": "count",
  "column": null,
  "groupBy": "resource_service_name",
  "window": {"since_unix_nano": "1730462400000000000", "until_unix_nano": "1730484000000000000"},
  "rows": [
    {"group": {"resource_service_name": "checkout"}, "function": "count", "value": 12, "sample_count": 12},
    {"group": {"resource_service_name": "payments"}, "function": "count", "value": 7,  "sample_count": 7}
  ]
}
```

**Cardinality cap.** `crashler.read.aggregate.max_groups` (default 200, env `CRASHLER_READ_AGGREGATE_MAX_GROUPS`) bounds the distinct group keys per request. A request whose `groupBy` would produce more groups than the cap returns HTTP 400; the system never silently truncates. Operators with high-cardinality fleets (e.g. >200 services) raise the cap or tighten the filter.

See `openspec/specs/read-aggregations/spec.md` for the normative contract.

### Grafana compatibility

The compat-shim layer at `/compat/<vendor>/` makes existing Grafana data sources (Tempo / Loki / Prometheus) talk to Crashler without rebuilding dashboards. Each shim sits behind a feature flag, defaults OFF, and ships only the connection-test endpoint in v1 — search and `query_range` are tracked as follow-ups.

Per-shim feature flags (set at server boot):

- `CRASHLER_COMPAT_TEMPO_ENABLED` (default `false`) — enables `/compat/tempo/api/echo`. Pinned to Tempo 2.x.
- `CRASHLER_COMPAT_LOKI_ENABLED` (default `false`) — enables `/compat/loki/api/v1/labels`. Pinned to Loki 2.9.x.
- `CRASHLER_COMPAT_PROMETHEUS_ENABLED` (default `false`) — enables `/compat/prom/api/v1/labels`. Pinned to Prometheus 2.x.

When a flag is `false`, the route returns 404. When `true`, the route returns a Tempo/Loki/Prometheus-shaped response sufficient to satisfy a Grafana data source's "Test connection" probe and to populate label-browser dropdowns. All shim endpoints share the bearer-token auth and tenant scoping of the canonical `/v1/` endpoints.

Provisioning snippet for Grafana data sources lives at [`docs/grafana-datasources.example.yaml`](docs/grafana-datasources.example.yaml). Substitute your tenant's bearer token and your Crashler base URL.

What v1 explicitly does NOT do, per shim spec:

- Tempo: no TraceQL (`q=`), no streaming, no `X-Scope-OrgID`. Search and trace-by-ID are deferred.
- Loki: no regex selectors (`=~`), no LogQL aggregations, no range vectors. `query_range` and `/label/{name}/values` are deferred.
- Prometheus: no PromQL functions outside `count_over_time` and `sum by` (and those are deferred too in v1; only `/labels` is shipped). No comparison operators, no recording rules.

See `openspec/specs/compat-shims/spec.md` plus the per-vendor `compat-tempo`, `compat-loki`, `compat-prometheus` capabilities for the normative contracts.

### Examples on the spec

Every read-API query parameter declares an OpenAPI `example` so the Swagger UI at `/docs` auto-fills its "Try it" form with realistic values. `since` becomes `1h`, `traceId` becomes a real-shape 32-hex-char string, `severityNumber` becomes `17`, and so on. Generated clients (openapi-generator and friends) pick up the same examples as fixture defaults.

The raw OpenAPI 3.1 document at `/docs.jsonopenapi` is the canonical consumer contract.

For contributors: every new read-API query parameter declared via API Platform must carry an `openApi: new OpenApiParameter(...)` block with a realistic `example` value. The lint command `bin/console app:openapi:lint-examples` enforces the rule and runs as part of the test suite. See `openspec/specs/read-api/spec.md` (sections starting "OpenAPI document carries examples ...") for the normative requirement.

### Operator/debug recipes — DuckDB on the file tree

The HTTP read API is the contract. The DuckDB recipes below are operator/debug tooling for ad-hoc deep dives directly on the Parquet files (typically over SSH on the host). They are NOT a stable interface — schema renames between versions can break them.

DuckDB reads the file tree directly. Logs:

```bash
duckdb -c "
  SELECT
    _schema_id,
    resource_service_name,
    severity_text,
    body_json,
    attributes_json
  FROM read_parquet('var/share/logs/<slug>/**/*.parquet', hive_partitioning=true)
  WHERE date = '2026-05-03'
    AND severity_number >= 17
    AND resource_service_name = 'checkout'
  LIMIT 100;
"
```

Traces:

```bash
duckdb -c "
  SELECT
    _schema_id,
    resource_service_name,
    name,
    kind_text,
    duration_nano,
    http_response_status_code,
    status_text,
    status_message
  FROM read_parquet('var/share/traces/<slug>/**/*.parquet', hive_partitioning=true)
  WHERE _schema_id = 'traces/v1'
    AND date = '2026-05-03'
    AND kind_text = 'SERVER'
    AND http_response_status_code >= 500
  ORDER BY start_time_unix_nano DESC
  LIMIT 100;
"
```

Metrics:

```bash
duckdb -c "
  SELECT
    _schema_id,
    resource_service_name,
    metric_name,
    metric_type,
    metric_unit,
    aggregation_temporality_text,
    value_double,
    value_int,
    count,
    sum,
    -- bucket structure for histograms; null for sum/gauge
    json_extract(buckets_json, '$.bucketCounts') AS bucket_counts
  FROM read_parquet('var/share/metrics/<slug>/**/*.parquet', hive_partitioning=true)
  WHERE _schema_id = 'metrics/v1'
    AND date = '2026-05-03'
    AND metric_name = 'http.server.request.duration'
    AND metric_type = 'HISTOGRAM'
  ORDER BY time_unix_nano DESC
  LIMIT 100;
"
```

Each metric type populates a different value column:

- `SUM` / `GAUGE` → `value_double` XOR `value_int` (typed; one is non-null per row)
- `HISTOGRAM` / `EXPONENTIAL_HISTOGRAM` / `SUMMARY` → `count`, `sum`, `min`, `max` as scalars; the corresponding `buckets_json` / `exponential_histogram_json` / `quantiles_json` blob carries the full structure for `json_extract` queries.

Prefer `HISTOGRAM` or `EXPONENTIAL_HISTOGRAM` over `SUMMARY` when emitting from your application: OpenTelemetry has deprecated Summary in favour of Histogram (Crashler still ingests it for compatibility but the column shape mirrors the deprecation guidance).

Event-time range queries should filter on `time_unix_nano` (logs / metrics) or `start_time_unix_nano` (traces) rather than the partition columns: a late-arriving record lands in the partition corresponding to its *ingest* time, not its event time. Multi-version reads can branch on `_schema_version`/`_schema_id` to handle column renames across schema versions.

## Running

```bash
symfony serve         # or your web server of choice
```

Send a test request from the OpenTelemetry Collector's `otlphttp` exporter or any OTel SDK. All three signals share the same auth header (`Authorization: Bearer <plaintext-token>`); the URLs are:

- Logs: `http://localhost:8000/v1/logs`
- Traces: `http://localhost:8000/v1/traces`
- Metrics: `http://localhost:8000/v1/metrics`

## Tests

Tests are organized into three suites:

- `tests/Unit/` — pure logic, no I/O
- `tests/Component/` — touches the local filesystem in temp directories; does not boot the kernel
- `tests/Functional/` — full Symfony kernel via `WebTestCase` + `zenstruck/browser`

```bash
composer test            # run all suites, no coverage (fast inner-loop)
composer test:coverage   # run all suites with coverage and enforce thresholds
```

## Deployment

Deployer is pre-configured for the Symfony recipe in [`deploy.php`](deploy.php). Hosts come **purely from environment variables** so no hostnames or paths land in this public repo.

For each stage you deploy to, set `${STAGE}_DEPLOY_*` variables — either as real env vars or in a gitignored `.env.deploy` (see [`.env.deploy.example`](.env.deploy.example) for the schema). Stages with no `HOST` set are silently skipped.

```bash
# Local one-shot:
PRODUCTION_DEPLOY_HOST=server.example.com \
PRODUCTION_DEPLOY_PATH=/var/www/crashler \
PRODUCTION_DEPLOY_USER=deployer \
  dep deploy production

# Or copy .env.deploy.example to .env.deploy, fill in values, then:
dep deploy production
```

The Symfony recipe handles cache clearing, vendor install (`--no-dev --optimize-autoloader`), and Symfony console wiring. Shared dirs persist across releases:

- `var/log` — application logs
- `var/share` — ingested Parquet files (deploys never re-emit existing files)

Shared file `.env.local` carries per-host secrets (e.g. `DATABASE_URL`, `CRASHLER_*` overrides).

## Design constraints (v1)

These are deliberate choices made for the first release; future changes will revisit them:

- **One Parquet file per request.** No write-ahead log, no batching across requests.
- **Synchronous Parquet write.** A 200 response means the file is fsync'd and renamed into its final path. The HTTP request waits on Parquet encoding.
- **Partition by ingest time, not event time.** Late-arriving logs are queryable but require column-level time filters.
- **Tenant changes require a redeploy.** No runtime CRUD for tenants or tokens.
- **No background workers, no console commands.** The web server is the only running process.
- **At-least-once via client retry.** A 5xx response means the request was fully rejected; the OTLP client retries the full batch. The server keeps no buffer.
- **Parquet schema is internal.** The query layer (planned, not yet shipped) will be the public read contract. Schema YAMLs are versioned so renames don't break existing files.

## Schema-breaking deploys

When a schema YAML rename or column removal lands, existing Parquet files become unreadable by the new column conventions. For deployments where the on-disk data has no retention value (e.g. early development), set `CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1` for a single `dep deploy` invocation:

```bash
CRASHLER_PURGE_OLD_LOGS_ON_DEPLOY=1 dep deploy production
```

This fires the `crashler:purge_old_logs` task before vendors install, removing every `*.parquet` under `<deploy_path>/shared/var/share/logs/`. **Unset the flag for subsequent deploys** so it can't accidentally fire on a host that has accumulated meaningful data. For migrations where data must be preserved, write a one-shot Deployer task that reads each old file and rewrites it with the new schema (option γ in the change's design.md).
