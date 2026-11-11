-- Pin one dataset to one stable Spotify account without changing existing keys.
CREATE TABLE spotify_account_binding (
    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
    account_sha256 char(64) NOT NULL CHECK (account_sha256 ~ '^[0-9a-f]{64}$'),
    verified_token_sha256 char(64) NOT NULL CHECK (verified_token_sha256 ~ '^[0-9a-f]{64}$'),
    bound_at timestamptz NOT NULL DEFAULT now(),
    verified_at timestamptz NOT NULL DEFAULT now()
);
CREATE FUNCTION reject_spotify_account_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Spotify dataset account binding is immutable';
END;
$$;
CREATE TRIGGER spotify_account_immutable
    BEFORE UPDATE OF account_sha256 OR DELETE ON spotify_account_binding
    FOR EACH ROW EXECUTE FUNCTION reject_spotify_account_mutation();
CREATE TRIGGER spotify_account_no_truncate
    BEFORE TRUNCATE ON spotify_account_binding
    FOR EACH STATEMENT EXECUTE FUNCTION reject_spotify_account_mutation();
