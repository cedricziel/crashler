## ADDED Requirements

### Requirement: Iceberg mode delegation

When `CRASHLER_TABLE_FORMAT=iceberg` is set, the log-storage writer SHALL delegate file commit to the Iceberg table writer specified by the `iceberg-storage` capability. The Parquet encoding (schema, column types, row group size, compression, universal `_schema_*` columns) SHALL be unchanged from Hive mode; only the post-write commit path SHALL differ. When `CRASHLER_TABLE_FORMAT` is unset or equals `hive`, the writer SHALL retain the legacy Hive-partitioned behavior described in this capability's other requirements without modification.

#### Scenario: Iceberg mode produces an Iceberg table for logs
- **WHEN** `CRASHLER_TABLE_FORMAT=iceberg` is set and a request for tenant `acme` is accepted
- **THEN** the resulting Parquet file is written under `<APP_SHARE_DIR>/iceberg/logs/acme/data/date=…/hour=…/`
- **AND** an Iceberg snapshot is committed referencing it
- **AND** no file is written under the legacy `<APP_SHARE_DIR>/logs/acme/` tree

#### Scenario: Hive mode is unchanged
- **WHEN** `CRASHLER_TABLE_FORMAT` is unset
- **THEN** writes follow the legacy `<APP_SHARE_DIR>/logs/<tenant>/date=…/hour=…/part-<ulid>.parquet` layout
- **AND** no `<APP_SHARE_DIR>/iceberg/` tree is created

#### Scenario: Parquet encoding is identical across modes
- **WHEN** the same OTLP request is processed once in `hive` mode and once in `iceberg` mode
- **THEN** the Parquet files produced have byte-identical schema, identical row counts, and identical column values
- **AND** they differ only in their on-disk path and surrounding catalog/manifest metadata
