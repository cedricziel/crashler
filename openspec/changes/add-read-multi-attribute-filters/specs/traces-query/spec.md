## MODIFIED Requirements

### Requirement: Trace-specific filters

The Trace Resource SHALL declare the following `#[ApiFilter]`s in addition to the common filters defined in `read-api`:

- `name` — operation name match. Exact match by default; supports a single leading `*` or trailing `*` for prefix/suffix wildcards (no full glob, no regex). Compiles to `ColumnEquals('name', v)` / `ColumnLikePrefix('name', prefix)` / `ColumnLikeSuffix('name', suffix)`
- `kind` — exact match on `kind_text` (`UNSPECIFIED` | `INTERNAL` | `SERVER` | `CLIENT` | `PRODUCER` | `CONSUMER`)
- `statusCode` — exact match on `status_text` (`UNSET` | `OK` | `ERROR`)
- `httpStatusCodeMin` — inclusive lower bound on `http_response_status_code`; row-group push-down eligible
- `traceId` — equality on `trace_id_hex` (32 lowercase hex chars; alias for the `/v1/traces/{traceId}` Item operation)
- `parentSpanId` — equality on `parent_span_id_hex` (16 lowercase hex chars; finds child spans of a given parent)
- `attribute.<key>` — equality via `JsonAttributeEquals('attributes_json', key, v)` (decoded JSON walk, not substring). Multiple distinct `attribute.<key>` filters compose with logical AND in a single request, up to the per-request cap defined by `read-api` (`crashler.read.max_attribute_filters`, default 5).

#### Scenario: name with trailing wildcard
- **WHEN** `GET /v1/traces?name=GET+/orders/*&since=1h`
- **THEN** every returned row's `name` starts with `GET /orders/`

#### Scenario: kind enum mismatch rejected
- **WHEN** `GET /v1/traces?kind=BANANA&since=1h`
- **THEN** the response status is 400 with a message listing the supported `kind` values

#### Scenario: httpStatusCodeMin filters by HTTP status
- **WHEN** `GET /v1/traces?httpStatusCodeMin=500&since=1h`
- **THEN** every returned row has `httpResponseStatusCode >= 500`

#### Scenario: parentSpanId finds child spans
- **WHEN** `GET /v1/traces?parentSpanId=051581bf3cb55c13&since=1h`
- **THEN** every returned row has `parentSpanIdHex == "051581bf3cb55c13"`

#### Scenario: Multiple attribute filters compose with AND
- **WHEN** `GET /v1/traces?attribute.http.method=POST&attribute.http.route=/checkout&since=1h`
- **THEN** the request is accepted
- **AND** every returned span's decoded `attributesJson` carries entries for both `http.method=POST` and `http.route=/checkout`

#### Scenario: Six attribute filters in one request rejected
- **WHEN** the request carries six distinct `attribute.<key>` parameters (and the configured cap is 5)
- **THEN** the response status is 400 with a message naming the cap (5)
