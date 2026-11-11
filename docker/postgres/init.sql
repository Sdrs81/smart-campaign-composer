-- Runs once, on first start of an empty data volume. Integration tests use their own
-- database so they can migrate and wipe it without touching the development data.
-- The owner defaults to POSTGRES_USER, which is the user the application connects as.
CREATE DATABASE campaign_test;
