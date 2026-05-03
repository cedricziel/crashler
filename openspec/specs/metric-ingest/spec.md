## Purpose

Defines the HTTP API for receiving OpenTelemetry metric payloads. Accepts OTLP/HTTP requests in either JSON or binary protobuf encoding, optionally gzip-compressed, authenticates them against the tenant registry, decodes into a common DTO tree, and synchronously writes to the storage layer before responding. A 200 response means the data-points are durably committed; on any error the request is fully rejected and the OTLP client is expected to retry.

## Requirements

### Requirement: OTLP/HTTP metrics endpoint

The system SHALL expose `POST /v1/metrics` accepting OTLP/HTTP request bodies in either of the two encodings defined by the OpenTelemetry OTLP/HTTP specification for metric data:

- **JSON** — `Content-Type: application/json`, proto3-JSON encoding of `ExportMetricsServiceRequest`
- **Protobuf** — `Content-Type: application/x-protobuf`, binary protobuf encoding of `ExportMetricsServiceRequest`

Either encoding MAY be optionally compressed with `Content-Encoding: gzip`. Content-Type parameters (e.g. `; charset=utf-8`) SHALL be tolerated and ignored. The endpoint SHALL be served by the existing Symfony application without a separate process or port.

#### Scenario: Plain JSON body accepted
- **WHEN** a valid OTLP/HTTP-JSON `ExportMetricsServiceRequest` is POSTed with `Content-Type: application/json`
- **THEN** the system processes the data-points
- **AND** responds with HTTP 200

#### Scenario: JSON body with charset parameter accepted
- **WHEN** a valid OTLP/HTTP-JSON request is POSTed with `Content-Type: application/json; charset=utf-8`
- **THEN** the system processes the data-points as JSON
- **AND** responds with HTTP 200

#### Scenario: Plain protobuf body accepted
- **WHEN** a valid binary `ExportMetricsServiceRequest` is POSTed with `Content-Type: application/x-protobuf`
- **THEN** the system processes the data-points via the protobuf decoder
- **AND** responds with HTTP 200
- **AND** the resulting Parquet rows are indistinguishable from the JSON encoding of the same logical request

#### Scenario: Gzip-compressed body accepted (either encoding)
- **WHEN** a valid OTLP request is POSTed with either `Content-Type` and `Content-Encoding: gzip` containing gzip-compressed bytes
- **THEN** the system decompresses and processes the data-points using the decoder selected by Content-Type
- **AND** responds with HTTP 200

#### Scenario: Unsupported Content-Type rejected
- **WHEN** a request arrives with a `Content-Type` other than `application/json` or `application/x-protobuf`
- **THEN** the system responds with HTTP 415 Unsupported Media Type
- **AND** no data-points are persisted

#### Scenario: Malformed JSON body rejected
- **WHEN** the request body is not valid JSON and Content-Type is `application/json`
- **THEN** the system responds with HTTP 400
- **AND** no data-points are persisted

#### Scenario: Malformed protobuf body rejected
- **WHEN** the request body cannot be parsed as `ExportMetricsServiceRequest` and Content-Type is `application/x-protobuf`
- **THEN** the system responds with HTTP 400
- **AND** no data-points are persisted

#### Scenario: Body schema mismatch rejected
- **WHEN** the JSON body parses but does not conform to `ExportMetricsServiceRequest` (e.g. missing `resourceMetrics`, wrong field types)
- **THEN** the system responds with HTTP 400 with an error description
- **AND** no data-points are persisted

### Requirement: Request body size limits

The system SHALL apply the same compressed and decompressed size caps already enforced for `/v1/logs` and `/v1/traces` (`CRASHLER_INGEST_MAX_BODY_BYTES`, default 4 MiB; `CRASHLER_INGEST_MAX_DECOMPRESSED_BYTES`, default 16 MiB). Requests exceeding either limit SHALL be rejected with HTTP 413 Payload Too Large. The decompressed cap SHALL be enforced incrementally during streaming decompression.

#### Scenario: Compressed body over limit rejected before decompression
- **WHEN** a request arrives with a compressed body larger than `CRASHLER_INGEST_MAX_BODY_BYTES`
- **THEN** the system responds with HTTP 413 without attempting decompression

#### Scenario: Decompressed body over limit rejected during decompression
- **WHEN** the compressed body is within the compressed limit but decompresses past `CRASHLER_INGEST_MAX_DECOMPRESSED_BYTES`
- **THEN** the system responds with HTTP 413
- **AND** no data-points are persisted

### Requirement: Authentication required

The endpoint SHALL require successful bearer-token authentication (per the `tenants` capability) before processing any request body. Unauthenticated requests SHALL be rejected before body parsing.

#### Scenario: Unauthenticated request rejected before parsing
- **WHEN** a request arrives without a valid bearer token
- **THEN** the system responds with HTTP 401
- **AND** the request body is not parsed

### Requirement: Synchronous Parquet write before response

For every successfully authenticated and validated request, the system SHALL synchronously write all decoded data-points into a single Parquet file under the tenant's metrics directory and SHALL only return HTTP 200 after the file has been fsync'd and renamed into its final partitioned path. The handler SHALL NOT use any intermediate write-ahead log, message queue, or other durable buffer.

#### Scenario: 200 implies file durably committed
- **WHEN** the system responds with HTTP 200 to an OTLP request containing N data-points across M metrics
- **THEN** a Parquet file containing all N data-point rows exists at its final path under `<storage-root>/metrics/<tenant-slug>/…`
- **AND** the file's parent directories were created on disk

#### Scenario: Persistence failure surfaces as 5xx
- **WHEN** the Parquet write or rename fails
- **THEN** the system responds with HTTP 5xx and an OTLP-shaped error body
- **AND** any `.tmp` file written for this request is unlinked

#### Scenario: One file per accepted request
- **WHEN** N requests are processed successfully
- **THEN** exactly N new Parquet files are produced (with N distinct ULIDs)
- **AND** no data-point from one request appears in another request's file

#### Scenario: Empty data-point arrays produce no file
- **WHEN** a request decodes successfully but every Metric has an empty data-points array
- **THEN** the system responds with HTTP 200
- **AND** no Parquet file is written

### Requirement: OTLP-compliant response shape

On a successful request, the system SHALL respond with HTTP 200, `Content-Type: application/json`, and an `ExportMetricsServiceResponse`-shaped JSON body. When all data-points were accepted, the body SHALL be `{}` or contain `partialSuccess` only with `rejectedDataPoints: 0`. On request errors, the system SHALL respond with the appropriate HTTP status (400, 401, 413, 415, 5xx) and a JSON body containing at minimum a top-level `message` field describing the error. Per-data-point partial-success rejection is not used in v1: a request is either fully accepted (200) or fully rejected (4xx/5xx).

#### Scenario: Successful response shape
- **WHEN** a valid request is fully accepted
- **THEN** the response status is 200
- **AND** the response Content-Type is `application/json`
- **AND** the response body is valid JSON conforming to `ExportMetricsServiceResponse`

#### Scenario: Error response shape
- **WHEN** any 4xx or 5xx response is returned
- **THEN** the body is valid JSON
- **AND** the body contains a `message` field describing the error in human-readable form

### Requirement: OTLP field decoding

The system SHALL decode OTLP/HTTP-JSON fields per the proto3 JSON mapping rules: `startTimeUnixNano` and `timeUnixNano` are accepted as either JSON numbers or numeric strings, `aggregationTemporality` is accepted as the enum integer value (0=UNSPECIFIED, 1=DELTA, 2=CUMULATIVE), data-point values use proto3-JSON variant tags (`asDouble`, `asInt`), and exemplar `traceId`/`spanId` are accepted as lowercase hex strings. Data-point attributes follow the same AnyValue rules already used for logs and traces (variant preserved across `stringValue`, `intValue`, `doubleValue`, `boolValue`, `bytesValue`, `arrayValue`, `kvlistValue`).

#### Scenario: timeUnixNano accepted as string
- **WHEN** a data-point carries `"timeUnixNano": "1714752000000000000"`
- **THEN** the system parses it as the int64 value 1714752000000000000

#### Scenario: timeUnixNano accepted as number
- **WHEN** a data-point carries `"timeUnixNano": 1714752000000000000`
- **THEN** the system parses it as the same int64 value (subject to JSON parser precision)

#### Scenario: Sum data-point asDouble preserved
- **WHEN** a Sum data-point carries `"asDouble": 12.5`
- **THEN** the row's `value_double` column is `12.5`
- **AND** the row's `value_int` column is NULL

#### Scenario: Sum data-point asInt preserved
- **WHEN** a Sum data-point carries `"asInt": "42"` (proto3-JSON int64-as-string)
- **THEN** the row's `value_int` column is `42`
- **AND** the row's `value_double` column is NULL

#### Scenario: Exemplar traceId hex decoded
- **WHEN** a data-point's exemplar carries `"traceId": "5b8aa5a2d2c872e8321cf37308d69df2"`
- **THEN** the corresponding entry inside `exemplars_json` carries `"traceId": "5b8aa5a2d2c872e8321cf37308d69df2"` (lowercase hex preserved for cross-signal joins)

### Requirement: At-least-once semantics rely on client retry

The system SHALL document that on any 5xx response the OTLP client is expected to retry per the OTLP specification's at-least-once delivery guarantee. The system SHALL NOT itself buffer rejected data-points for retry; a 5xx means the request was fully rejected and SHOULD be re-sent in full.

#### Scenario: 5xx implies no data-points persisted
- **WHEN** the system responds with HTTP 5xx to a request
- **THEN** zero data-points from the request are present on disk
- **AND** the client may safely retry the request without producing duplicates beyond what OTLP retry already implies
