-- Original evidence and artifact paths remain append-only and unchanged.
CREATE TABLE raw_objects (
    sha256 char(64) PRIMARY KEY CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
    stored_bytes bigint NOT NULL CHECK (stored_bytes >= 0),
    encoding text NOT NULL CHECK (encoding IN ('identity','gzip')),
    tier text NOT NULL CHECK (tier IN ('local','archive')),
    first_received_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE raw_storage_refs (
    artifact_id bigint PRIMARY KEY REFERENCES raw_artifacts(id),
    sha256 char(64) NOT NULL REFERENCES raw_objects(sha256),
    reference_bytes bigint NOT NULL CHECK (reference_bytes >= 0)
);
CREATE INDEX ON raw_storage_refs(sha256);
CREATE INDEX ON raw_artifacts(sha256);
CREATE TABLE raw_storage_runs (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    operation text NOT NULL,
    status text NOT NULL CHECK (status IN ('running','completed','failed')),
    started_at timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz,
    artifacts bigint NOT NULL DEFAULT 0,
    objects bigint NOT NULL DEFAULT 0,
    local_bytes_before bigint NOT NULL DEFAULT 0,
    local_bytes_after bigint NOT NULL DEFAULT 0,
    backup_verified boolean NOT NULL DEFAULT false,
    snapshot_name text,
    message text
);
