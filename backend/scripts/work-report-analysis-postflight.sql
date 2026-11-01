-- READ ONLY. Same verified environment and operator as preflight.
START TRANSACTION READ ONLY;
SELECT DATABASE(), CURRENT_USER(), VERSION(), @@hostname, @@foreign_key_checks;
SELECT migration, batch FROM migrations ORDER BY migration;
SELECT table_name, column_name, column_type, is_nullable FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name IN ('work_report_analyses', 'work_report_analysis_units')
ORDER BY table_name, ordinal_position;
SELECT table_name, index_name, non_unique, seq_in_index, column_name FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name IN ('work_report_analyses', 'work_report_analysis_units')
ORDER BY table_name, index_name, seq_in_index;
SELECT table_name, constraint_name, referenced_table_name, delete_rule FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE() AND table_name IN ('work_report_analyses', 'work_report_analysis_units');
SELECT COUNT(*) AS analyses FROM work_report_analyses;
SELECT COUNT(*) AS units FROM work_report_analysis_units;
-- Both new tables must be empty before enabling the feature.
-- Re-run aggregate counts in work-report-analysis-preflight.sql and compare to
-- the checkpoint while writers remain frozen. Compare P0 identity/fingerprints
-- through the existing release-preservation procedure, not just row counts.
COMMIT;
