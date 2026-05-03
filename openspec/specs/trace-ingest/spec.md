## Purpose

Defines the HTTP API for receiving OpenTelemetry trace payloads. Accepts OTLP/HTTP requests in either JSON or binary protobuf encoding, optionally gzip-compressed, authenticates them against the tenant registry, decodes into a common DTO tree, and synchronously writes to the storage layer before responding. A 200 response means the spans are durably committed; on any error the request is fully rejected and the OTLP client is expected to retry.

## Requirements

### Requirement: OTLP/HTTP traces endpoint

The system SHALL expose `POST /v1/traces` accepting OTLP/HTTP request bodies in either of the two encodings defined by the OpenTelemetry OTLP/HTTP specification for trace data:

- **JSON** — `Content-Type: application/json`, proto3-JSON encoding of `ExportTraceServiceRequest`
- **Protobuf** — `Content-Type: application/x-protobuf`, binary protobuf encoding of `ExportTraceServiceRequest`

Either encoding MAY be optionally compressed with `Content-Encoding: gzip`. Content-Type parameters (e.g. `; charset=utf-8`) SHALL be tolerated and ignored. The endpoint SHALL be served by the existing Symfony application without a separate process or port.

#### Scenario: Plain JSON body accepted
- **WHEN** a valid OTLP/HTTP-JSON `ExportTraceServiceRequest` is POSTed with `Content-Type: application/json`
- **THEN** the system processes the spans
- **AND** responds with HTTP 200

#### Scenario: JSON body with charset parameter accepted
- **WHEN** a valid OTLP/HTTP-JSON request is POSTed with `Content-Type: application/json; charset=utf-8`
- **THEN** the system processes the spans as JSON
- **AND** responds with HTTP 200

#### Scenario: Plain protobuf body accepted
- **WHEN** a valid binary `ExportTraceServiceRequest` is POSTed with `Content-Type: application/x-protobuf`
- **THEN** the system processes the spans via the protobuf decoder
- **AND** responds with HTTP 200
- **AND** the resulting Parquet rows are indistinguishable from the JSON encoding of the same logical request

#### Scenario: Gzip-compressed body accepted (either encoding)
- **WHEN** a valid OTLP request is POSTed with either `Content-Type` and `Content-Encoding: gzip` containing gzip-compressed bytes
- **THEN** the system decompresses and processes the spans using the decoder selected by Content-Type
- **AND** responds with HTTP 200

#### Scenario: Unsupported Content-Type rejected
- **WHEN** a request arrives with a `Content-Type` other than `application/json` or `application/x-protobuf`
- **THEN** the system responds with HTTP 415 Unsupported Media Type
- **AND** no spans are persisted

#### Scenario: Malformed JSON body rejected
- **WHEN** the request body is not valid JSON and Content-Type is `application/json`
- **THEN** the system responds with HTTP 400
- **AND** no spans are persisted

#### Scenario: Malformed protobuf body rejected
- **WHEN** the request body cannot be parsed as `ExportTraceServiceRequest` and Content-Type is `application/x-protobuf`
- **THEN** the system responds with HTTP 400
- **AND** no spans are persisted

#### Scenario: Body schema mismatch rejected
- **WHEN** the JSON body parses but does not conform to `ExportTraceServiceRequest` (e.g. missing `resourceSpans`, wrong field types)
- **THEN** the system responds with HTTP 400 with an error description
- **AND** no spans are persisted

### Requirement: Request body size limits

The system SHALL apply the same compressed and decompressed size caps already enforced for `/v1/logs` (`CRASHLER_INGEST_MAX_BODY_BYTES`, default 4 MiB; `CRASHLER_INGEST_MAX_DECOMPRESSED_BYTES`, default 16 MiB). Requests exceeding either limit SHALL be rejected with HTTP 413 Payload Too Large. The decompressed cap SHALL be enforced incrementally during streaming decompression.

#### Scenario: Compressed body over limit rejected before decompression
- **WHEN** a request arrives with a compressed body larger than `CRASHLER_INGEST_MAX_BODY_BYTES`
- **THEN** the system responds with HTTP 413 without attempting decompression

#### Scenario: Decompressed body over limit rejected during decompression
- **WHEN** the compressed body is within the compressed limit but decompresses past `CRASHLER_INGEST_MAX_DECOMPRESSED_BYTES`
- **THEN** the system responds with HTTP 413
- **AND** no spans are persisted

### Requirement: Authentication required

The endpoint SHALL require successful bearer-token authentication (per the `tenants` capability) before processing any request body. Unauthenticated requests SHALL be rejected before body parsing.

#### Scenario: Unauthenticated request rejected before parsing
- **WHEN** a request arrives without a valid bearer token
- **THEN** the system responds with HTTP 401
- **AND** the request body is not parsed

### Requirement: Synchronous Parquet write before response

For every successfully authenticated and validated request, the system SHALL synchronously write all decoded spans into a single Parquet file under the tenant's traces directory and SHALL only return HTTP 200 after the file has been fsync'd and renamed into its final partitioned path. The handler SHALL NOT use any intermediate write-ahead log, message queue, or other durable buffer.

#### Scenario: 200 implies file durably committed
- **WHEN** the system responds with HTTP 200 to an OTLP request containing N spans
- **THEN** a Parquet file containing all N spans exists at its final path under `<storage-root>/traces/<tenant-slug>/…`
- **AND** the file's parent directories were created on disk

#### Scenario: Persistence failure surfaces as 5xx
- **WHEN** the Parquet write or rename fails
- **THEN** the system responds with HTTP 5xx and an OTLP-shaped error body
- **AND** any `.tmp` file written for this request is unlinked

#### Scenario: One file per accepted request
- **WHEN** N requests are processed successfully
- **THEN** exactly N new Parquet files are produced (with N distinct ULIDs)
- **AND** no span from one request appears in another request's file

### Requirement: OTLP-compliant response shape

On a successful request, the system SHALL respond with HTTP 200, `Content-Type: application/json`, and an `ExportTraceServiceResponse`-shaped JSON body. When all spans were accepted, the body SHALL be `{}` or contain `partialSuccess` only with `rejectedSpans: 0`. On request errors, the system SHALL respond with the appropriate HTTP status (400, 401, 413, 415, 5xx) and a JSON body containing at minimum a top-level `message` field describing the error. Per-span partial-success rejection is not used in v1: a request is either fully accepted (200) or fully rejected (4xx/5xx).

#### Scenario: Successful response shape
- **WHEN** a valid request is fully accepted
- **THEN** the response status is 200
- **AND** the response Content-Type is `application/json`
- **AND** the response body is valid JSON conforming to `ExportTraceServiceResponse`

#### Scenario: Error response shape
- **WHEN** any 4xx or 5xx response is returned
- **THEN** the body is valid JSON
- **AND** the body contains a `message` field describing the error in human-readable form

### Requirement: OTLP field decoding

The system SHALL decode OTLP/HTTP-JSON fields per the proto3 JSON mapping rules: `traceId`, `spanId`, and `parentSpanId` are accepted as lowercase hex strings, `startTimeUnixNano` and `endTimeUnixNano` are accepted as either JSON numbers or numeric strings, and `kind` is accepted as the SpanKind enum integer value. Span attributes follow the same AnyValue rules already used for logs (variant preserved across `stringValue`, `intValue`, `doubleValue`, `boolValue`, `bytesValue`, `arrayValue`, `kvlistValue`).

#### Scenario: timeUnixNano accepted as string
- **WHEN** a span carries `"startTimeUnixNano": "1714752000000000000"`
- **THEN** the system parses it as the int64 value 1714752000000000000

#### Scenario: timeUnixNano accepted as number
- **WHEN** a span carries `"startTimeUnixNano": 1714752000000000000`
- **THEN** the system parses it as the same int64 value (subject to JSON parser precision)

#### Scenario: traceId hex decoded
- **WHEN** a span carries `"traceId": "5b8aa5a2d2c872e8321cf37308d69df2"`
- **THEN** the system stores the corresponding 16-byte value internally and the lowercase hex string in the `trace_id_hex` column

#### Scenario: Span event AnyValue preserved
- **WHEN** a span event's attribute value is an AnyValue containing a non-string variant
- **THEN** the AnyValue's variant tag is preserved when serialized into the `events_json` column

### Requirement: At-least-once semantics rely on client retry

The system SHALL document that on any 5xx response the OTLP client is expected to retry per the OTLP specification's at-least-once delivery guarantee. The system SHALL NOT itself buffer rejected spans for retry; a 5xx means the request was fully rejected and SHOULD be re-sent in full.

#### Scenario: 5xx implies no spans persisted
- **WHEN** the system responds with HTTP 5xx to a request
- **THEN** zero spans from the request are present on disk
- **AND** the client may safely retry the request without producing duplicates beyond what OTLP retry already implies
