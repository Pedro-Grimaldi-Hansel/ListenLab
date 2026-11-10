ALTER TABLE raw_storage_runs ADD COLUMN archive_bytes bigint NOT NULL DEFAULT 0 CHECK (archive_bytes >= 0);
ALTER TABLE raw_storage_runs ADD COLUMN snapshot_count integer NOT NULL DEFAULT 0 CHECK (snapshot_count >= 0);
-- New occurrences refer to an immutable RAW plus item_index instead of copying JSON.
-- Existing JSON payloads remain untouched and readable.
ALTER TABLE ingestion_records ALTER COLUMN payload DROP NOT NULL;
ALTER TABLE ingestion_records ADD COLUMN payload_sha256 char(64) CHECK (payload_sha256 ~ '^[0-9a-f]{64}$');
ALTER TABLE ingestion_records ADD CONSTRAINT ingestion_record_payload_available CHECK (payload IS NOT NULL OR payload_sha256 IS NOT NULL);
