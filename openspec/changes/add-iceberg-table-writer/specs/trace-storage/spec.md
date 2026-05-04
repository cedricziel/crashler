## ADDED Requirements

### Requirement: Iceberg mode delegation

When `CRASHLER_TABLE_FORMAT=iceberg` is set, the trace-storage writer SHALL delegate file commit to the Iceberg table writer specified by the `iceberg-storage` capability. Parquet encoding for traces SHALL be unchanged from Hive mode; only the post-write commit path SHALL differ. When `CRASHLER_TABLE_FORMAT` is unset or equals `hive`, the writer SHALL retain the legacy Hive-partitioned behavior described in this capability's other requirements without modification.

#### Scenario: Iceberg mode produces an Iceberg table for traces
- **WHEN** `CRASHLER_TABLE_FORMAT=iceberg` is set and a request for tenant `acme` is accepted
- **THEN** the resulting Parquet file is written under `<APP_SHARE_DIR>/iceberg/traces/acme/data/date=…/hour=…/`
- **AND** an Iceberg snapshot is committed referencing it

#### Scenario: Hive mode is unchanged
- **WHEN** `CRASHLER_TABLE_FORMAT` is unset
- **THEN** writes follow the legacy Hive-partitioned trace layout
