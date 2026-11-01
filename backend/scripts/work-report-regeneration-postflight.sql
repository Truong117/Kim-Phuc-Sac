-- READ ONLY. Same verified environment/operator as preflight, writers still frozen.
START TRANSACTION READ ONLY;
SELECT DATABASE(), CURRENT_USER(), VERSION(), @@hostname, @@foreign_key_checks;
SELECT migration, batch FROM migrations ORDER BY id;
-- Require exact reviewed 24-entry manifest, no pending/unknown entries.
SELECT table_name, column_name, column_type, is_nullable FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name IN ('work_report_analysis_revisions', 'work_report_regeneration_requests')
ORDER BY table_name, ordinal_position;
SELECT table_name, index_name, non_unique, seq_in_index, column_name FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name IN ('work_report_analysis_revisions', 'work_report_regeneration_requests')
ORDER BY table_name, index_name, seq_in_index;
-- Verify PK analysis_id; unique(root_analysis_id, revision_number); unique(org, requester, idempotency_key).
-- Verify organization/fingerprint index and all eight restrictive foreign keys against migration DDL.
SELECT table_name, constraint_name, referenced_table_name, delete_rule FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE() AND table_name IN ('work_report_analysis_revisions', 'work_report_regeneration_requests');
SELECT COUNT(*) AS revisions FROM work_report_analysis_revisions;
SELECT COUNT(*) AS operations FROM work_report_regeneration_requests;
-- Both new tables empty before feature enable. Compare ALL preflight row counts AND approved fingerprints,
-- including existing M23 snapshots/config/results/units; no backfill, no previous analysis modified.
COMMIT;
