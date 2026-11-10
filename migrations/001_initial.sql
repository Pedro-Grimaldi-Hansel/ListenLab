CREATE TABLE data_sources (
    id smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code text NOT NULL UNIQUE
);
INSERT INTO data_sources(code) VALUES ('spotify_api'), ('spotify_account_export'), ('spotify_extended_export');

CREATE TABLE ingestion_batches (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id smallint NOT NULL REFERENCES data_sources(id),
    operation text NOT NULL,
    started_at timestamptz NOT NULL DEFAULT now(),
    finished_at timestamptz,
    status text NOT NULL DEFAULT 'running' CHECK (status IN ('running','completed','partial','failed')),
    records_received bigint NOT NULL DEFAULT 0,
    records_processed bigint NOT NULL DEFAULT 0,
    records_inserted bigint NOT NULL DEFAULT 0,
    records_updated bigint NOT NULL DEFAULT 0,
    records_skipped bigint NOT NULL DEFAULT 0,
    records_failed bigint NOT NULL DEFAULT 0,
    plays_created bigint NOT NULL DEFAULT 0,
    metadata jsonb NOT NULL DEFAULT '{}'
);
CREATE INDEX ON ingestion_batches(source_id, started_at);

CREATE TABLE raw_artifacts (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    batch_id bigint NOT NULL REFERENCES ingestion_batches(id),
    path text NOT NULL UNIQUE,
    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    size_bytes bigint NOT NULL CHECK (size_bytes >= 0),
    received_at timestamptz NOT NULL DEFAULT now(),
    http_status smallint,
    metadata jsonb NOT NULL DEFAULT '{}'
);
CREATE INDEX ON raw_artifacts(batch_id);
CREATE FUNCTION reject_raw_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'RAW artifact metadata is append-only';
END;
$$;
CREATE TRIGGER raw_artifacts_append_only BEFORE UPDATE OR DELETE ON raw_artifacts
    FOR EACH ROW EXECUTE FUNCTION reject_raw_mutation();

CREATE TABLE artists (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider text NOT NULL DEFAULT 'spotify',
    external_id text,
    external_uri text,
    name text,
    observed_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(provider, external_id)
);
CREATE TABLE albums (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider text NOT NULL DEFAULT 'spotify',
    external_id text,
    external_uri text,
    name text,
    release_date text,
    release_date_precision text CHECK (release_date_precision IN ('year','month','day')),
    observed_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(provider, external_id)
);
CREATE TABLE tracks (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider text NOT NULL DEFAULT 'spotify',
    external_id text,
    external_uri text,
    name text,
    album_id bigint REFERENCES albums(id),
    duration_ms integer CHECK (duration_ms >= 0),
    explicit boolean,
    is_local boolean,
    isrc text,
    linked_from_id text,
    linked_from_uri text,
    observed_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(provider, external_id)
);
CREATE TABLE track_artists (
    track_id bigint NOT NULL REFERENCES tracks(id),
    artist_id bigint NOT NULL REFERENCES artists(id),
    position integer NOT NULL CHECK (position >= 0),
    PRIMARY KEY(track_id, artist_id, position),
    UNIQUE(track_id, position)
);
CREATE INDEX ON track_artists(artist_id);

CREATE TABLE plays (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    track_id bigint NOT NULL REFERENCES tracks(id),
    played_at timestamptz NOT NULL,
    timestamp_semantics text NOT NULL,
    ms_played bigint CHECK (ms_played >= 0),
    reason_start text,
    reason_end text,
    shuffle boolean,
    skipped boolean,
    offline boolean,
    incognito_mode boolean,
    platform text,
    country text,
    context_type text,
    context_uri text,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ON plays(played_at);
CREATE INDEX ON plays(track_id);

-- One row for EVERY received occurrence, including overlaps and invalid items.
CREATE TABLE ingestion_records (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    raw_artifact_id bigint NOT NULL REFERENCES raw_artifacts(id),
    item_index integer NOT NULL CHECK (item_index >= 0),
    source_record_key char(64),
    payload jsonb NOT NULL,
    processing_status text NOT NULL DEFAULT 'pending' CHECK (
        processing_status IN ('pending','processed','partially_processed','duplicate','rejected','pending_review')
    ),
    normalizer_version text,
    processed_at timestamptz,
    UNIQUE(raw_artifact_id, item_index)
);
CREATE INDEX ON ingestion_records(source_record_key);

CREATE TABLE play_evidence (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    play_id bigint NOT NULL REFERENCES plays(id),
    source_id smallint NOT NULL REFERENCES data_sources(id),
    source_record_key char(64) NOT NULL,
    first_ingestion_record_id bigint NOT NULL UNIQUE REFERENCES ingestion_records(id),
    match_type text NOT NULL DEFAULT 'source_identity',
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(source_id, source_record_key)
);
CREATE INDEX ON play_evidence(play_id);
ALTER TABLE ingestion_records ADD COLUMN evidence_id bigint REFERENCES play_evidence(id);

CREATE TABLE ingestion_issues (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    batch_id bigint NOT NULL REFERENCES ingestion_batches(id),
    raw_artifact_id bigint REFERENCES raw_artifacts(id),
    ingestion_record_id bigint REFERENCES ingestion_records(id),
    code text NOT NULL,
    message text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ON ingestion_issues(batch_id);
