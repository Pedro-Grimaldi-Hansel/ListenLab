CREATE TABLE scheduler_runtime (
    name text PRIMARY KEY,
    instance_id char(32) NOT NULL,
    state text NOT NULL CHECK (state IN ('collecting', 'waiting', 'stopped')),
    interval_seconds integer NOT NULL CHECK (interval_seconds BETWEEN 15 AND 86400),
    started_at timestamptz NOT NULL,
    heartbeat_at timestamptz NOT NULL,
    cycle_started_at timestamptz,
    next_run_at timestamptz,
    last_exit_code integer,
    stopped_at timestamptz
);
