## ADDED Requirements

### Requirement: Iceberg mode delegation

When `CRASHLER_TABLE_FORMAT=iceberg` is set, the metric-storage writer SHALL delegate file commit to the Iceberg table writer specified by the `iceberg-storage` capability. Parquet encoding for metrics (including the `metric_type` discriminator and JSON-string columns for histogram buckets, exponential-histogram detail, summary quantiles, and exemplars) SHALL be unchanged from Hive mode; only the post-write commit path SHALL differ. When `CRASHLER_TABLE_FORMAT` is unset or equals `hive`, the writer SHALL retain the legacy Hive-partitioned behavior described in this capability's other requirements without modification.

#### Scenario: Iceberg mode produces an Iceberg table for metrics
- **WHEN** `CRASHLER_TABLE_FORMAT=iceberg` is set and a metrics request for tenant `acme` is accepted
- **THEN** the resulting Parquet file is written under `<APP_SHARE_DIR>/iceberg/metrics/acme/data/date=…/hour=…/`
- **AND** an Iceberg snapshot is committed referencing it

#### Scenario: Hive mode is unchanged
- **WHEN** `CRASHLER_TABLE_FORMAT` is unset
- **THEN** writes follow the legacy Hive-partitioned metric data-point layout
