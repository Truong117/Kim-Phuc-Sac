-- READ ONLY. M24 requires reviewed M1-M23 complete, exact ledger prefix, no unknown entries.
-- Same approved TLS/identity/migrator profile; stop on mismatch or partially existing M24 tables.
START TRANSACTION READ ONLY;
SELECT DATABASE(), CURRENT_USER(), VERSION(), @@hostname, @@foreign_key_checks;
SHOW SESSION STATUS LIKE 'Ssl_cipher';
SHOW GRANTS FOR CURRENT_USER;
SELECT migration, batch FROM migrations ORDER BY id;
SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()
AND table_name IN ('work_report_analysis_revisions', 'work_report_regeneration_requests');
-- Above must return zero rows. M23 tables must both exist with their reviewed columns/indexes/FKs.
SELECT table_name, column_name, column_type, is_nullable FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name IN ('work_report_analyses', 'work_report_analysis_units')
ORDER BY table_name, ordinal_position;
SELECT table_name, index_name, non_unique, seq_in_index, column_name FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name IN ('work_report_analyses', 'work_report_analysis_units')
ORDER BY table_name, index_name, seq_in_index;
SELECT table_name, constraint_name, referenced_table_name, delete_rule FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE() AND table_name IN ('work_report_analyses', 'work_report_analysis_units');
SELECT table_name, column_name, column_type FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name IN ('organizations', 'users', 'work_report_analyses') AND column_name = 'id';
SELECT 'work_report_analyses' AS object_name, COUNT(*) AS row_count FROM work_report_analyses
UNION ALL SELECT 'work_report_analysis_units', COUNT(*) FROM work_report_analysis_units
UNION ALL SELECT 'ai_usage_records', COUNT(*) FROM ai_usage_records;
-- Also retain all existing-table counts/fingerprints from M23 preflight under the same frozen-writer checkpoint.
-- M24 permits existing completed/pending M23 analyses; it must never update them.
COMMIT;
-- Separately inspect SHOW DDL JOBS / SHOW FULL PROCESSLIST; no competing DDL/migrator.
