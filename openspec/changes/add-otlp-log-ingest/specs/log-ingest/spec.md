## ADDED Requirements

### Requirement: OTLP/HTTP-JSON logs endpoint

The system SHALL expose `POST /v1/logs` accepting OTLP/HTTP-JSON request bodies as defined by the OpenTelemetry OTLP/HTTP specification for log data (proto3-JSON encoding of `ExportLogsServiceRequest`). The endpoint SHALL accept `Content-Type: application/json` and SHALL accept request bodies optionally compressed with `Content-Encoding: gzip`. The endpoint SHALL be served by the existing Symfony application without a separate process or port.

#### Scenario: Plain JSON body accepted
- **WHEN** a valid OTLP/HTTP-JSON `ExportLogsServiceRequest` is POSTed with `Content-Type: application/json`
- **THEN** the system processes the records
- **AND** responds with HTTP 200

#### Scenario: Gzip-compressed body accepted
- **WHEN** a valid OTLP request is POSTed with `Content-Type: application/json` and `Content-Encoding: gzip` containing gzip-compressed bytes
- **THEN** the system decompresses and processes the records
- **AND** responds with HTTP 200

#### Scenario: Wrong Content-Type rejected
- **WHEN** a request arrives with `Content-Type: application/x-protobuf`
- **THEN** the system responds with HTTP 415 Unsupported Media Type
- **AND** no records are persisted

#### Scenario: Malformed JSON body rejected
- **WHEN** the request body is not valid JSON
- **THEN** the system responds with HTTP 400
- **AND** no records are persisted

#### Scenario: Body schema mismatch rejected
- **WHEN** the JSON body parses but does not conform to `ExportLogsServiceRequest` (e.g., missing `resourceLogs`, wrong field types)
- **THEN** the system responds with HTTP 400 with an error description
- **AND** no records are persisted

### Requirement: Request body size limits

The system SHALL reject request bodies whose decompressed size exceeds a configured limit (default 16 MiB) with HTTP 413 Payload Too Large. The system SHALL also reject compressed request bodies whose compressed size exceeds a configured limit (default 4 MiB) before attempting decompression. The decompressed-size limit SHALL be enforced incrementally during streaming decompression so the handler does not allocate unbounded memory.

#### Scenario: Compressed body over limit rejected before decompression
- **WHEN** a request arrives with a compressed body larger than the compressed limit
- **THEN** the system responds with HTTP 413 without attempting decompression

#### Scenario: Decompressed body over limit rejected during decompression
- **WHEN** the compressed body is within the compressed limit but decompresses to more than the decompressed limit
- **THEN** the system responds with HTTP 413
- **AND** no records are persisted
- **AND** the decompression is aborted before reading the entire output into memory

### Requirement: Authentication required

The endpoint SHALL require successful bearer-token authentication (per the `tenants` capability) before processing any request body. Unauthenticated requests SHALL be rejected before body parsing.

#### Scenario: Unauthenticated request rejected before parsing
- **WHEN** a request arrives without a valid bearer token
- **THEN** the system responds with HTTP 401
- **AND** the request body is not parsed

### Requirement: Synchronous Parquet write before response

For every successfully authenticated and validated request, the system SHALL synchronously write all decoded log records into a single Parquet file under the tenant's storage directory and SHALL only return HTTP 200 after the file has been fsync'd and renamed into its final partitioned path. The handler SHALL NOT use any intermediate write-ahead log, message queue, or other durable buffer. If any step of the Parquet write fails (encoding, fsync, rename), the system SHALL return HTTP 5xx and SHALL leave no `.tmp` file behind.

#### Scenario: 200 implies file durably committed
- **WHEN** the system responds with HTTP 200 to an OTLP request containing N records
- **THEN** a Parquet file containing all N records exists at its final path
- **AND** the file's parent directories were created on disk
- **AND** no `.tmp` file remains for this request

#### Scenario: Parquet write failure surfaces as 5xx
- **WHEN** the Parquet write or rename fails (e.g., disk full, EIO)
- **THEN** the system responds with HTTP 5xx and an OTLP-shaped error body
- **AND** any `.tmp` file written for this request is unlinked

#### Scenario: One file per accepted request
- **WHEN** N requests are processed successfully
- **THEN** exactly N new Parquet files are produced (with N distinct ULIDs)
- **AND** no record from one request appears in another request's file

### Requirement: OTLP-compliant response shape

On a successful request, the system SHALL respond with HTTP 200, `Content-Type: application/json`, and an `ExportLogsServiceResponse`-shaped JSON body. When all records were accepted, the body SHALL be `{}` or contain `partialSuccess` only with `rejectedLogRecords: 0`. On request errors, the system SHALL respond with the appropriate HTTP status (400, 401, 413, 415, 5xx) and a JSON body containing at minimum a top-level `message` field describing the error. Per-record partial-success rejection is not used in v1: a request is either fully accepted (200) or fully rejected (4xx/5xx).

#### Scenario: Successful response shape
- **WHEN** a valid request is fully accepted
- **THEN** the response status is 200
- **AND** the response Content-Type is `application/json`
- **AND** the response body is valid JSON conforming to `ExportLogsServiceResponse`

#### Scenario: Error response shape
- **WHEN** any 4xx or 5xx response is returned
- **THEN** the body is valid JSON
- **AND** the body contains a `message` field describing the error in human-readable form

### Requirement: OTLP field decoding

The system SHALL decode OTLP/HTTP-JSON fields per the proto3 JSON mapping rules: `traceId` and `spanId` are accepted as lowercase hex strings, `timeUnixNano` and `observedTimeUnixNano` are accepted as either JSON numbers or numeric strings (because JavaScript cannot represent int64 precisely), and `severityNumber` is accepted as the enum integer value. The system SHALL preserve `body` values across the AnyValue type spectrum (string, int, double, bool, bytes, array, kvlist) without lossy coercion to string at decode time.

#### Scenario: timeUnixNano accepted as string
- **WHEN** a record carries `"timeUnixNano": "1714752000000000000"`
- **THEN** the system parses it as the int64 value 1714752000000000000

#### Scenario: timeUnixNano accepted as number
- **WHEN** a record carries `"timeUnixNano": 1714752000000000000`
- **THEN** the system parses it as the same int64 value (subject to JSON parser precision)

#### Scenario: traceId hex decoded
- **WHEN** a record carries `"traceId": "5b8aa5a2d2c872e8321cf37308d69df2"`
- **THEN** the system stores 16 bytes corresponding to that hex string

#### Scenario: AnyValue body preserved
- **WHEN** a record's `body` is an object (AnyValue) containing a non-string variant such as `{"intValue": "42"}`
- **THEN** the system serializes the AnyValue structure as JSON in the Parquet `body_json` column rather than collapsing it to the string `"42"`

### Requirement: At-least-once semantics rely on client retry

The system SHALL document that on any 5xx response the client is expected to retry per the OTLP specification's at-least-once delivery guarantee. The system SHALL NOT itself buffer rejected records for retry; a 5xx means the request was fully rejected and SHOULD be re-sent in full.

#### Scenario: 5xx implies no records persisted
- **WHEN** the system responds with HTTP 5xx to a request
- **THEN** zero records from the request are present on disk
- **AND** the client may safely retry the request without producing duplicates beyond what OTLP retry already implies
