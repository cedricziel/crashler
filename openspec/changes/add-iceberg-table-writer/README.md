# add-iceberg-table-writer

Pure-PHP embedded Iceberg v2 library that replaces the legacy Hive writer (no BC), commits in batched snapshots, ships the full v2 write surface (append, overwrite, row-delta, position+equality delete writers), and is namespaced for eventual extraction as a standalone Composer package (`cedricziel/iceberg-php`). Reader, schema/spec evolution, snapshot expiry, additional catalogs, and v3 readiness are staged across follow-up milestones M2-M9.
