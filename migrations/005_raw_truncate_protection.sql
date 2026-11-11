-- Statement-level protection also rejects accidental TRUNCATE by a table owner.
CREATE TRIGGER raw_artifacts_no_truncate
    BEFORE TRUNCATE ON raw_artifacts
    FOR EACH STATEMENT EXECUTE FUNCTION reject_raw_mutation();
