# add-otlp-trace-ingest

Receive OTLP/HTTP-JSON and -protobuf trace payloads at POST /v1/traces and store them as Hive-partitioned Parquet under <root>/traces/<tenant>/.
