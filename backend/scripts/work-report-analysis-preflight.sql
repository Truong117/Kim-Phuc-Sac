-- READ ONLY. Run using the environment's reviewed TLS/identity profile.
-- Stop on any mismatch. Save aggregate evidence; do not export report text.
START TRANSACTION READ ONLY;
SELECT DATABASE(), CURRENT_USER(), VERSION(), @@hostname, @@foreign_key_checks;
SHOW SESSION STATUS LIKE 'Ssl_cipher';
SHOW GRANTS FOR CURRENT_USER;
SELECT migration, batch FROM migrations ORDER BY migration;
-- Before M23: exact first 22 entries of StagingBootstrapPlan::EXPECTED_MIGRATIONS;
-- M23 absent, no unknown entries, both new tables absent.
SELECT table_name FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name IN ('work_report_analyses', 'work_report_analysis_units');
SELECT table_name, column_name, column_type FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name IN ('organizations', 'users', 'departments', 'ai_usage_records') AND column_name = 'id';
-- Preserve these aggregate counts in the approved checkpoint evidence.
SELECT 'users' AS object_name, COUNT(*) AS row_count FROM users
UNION ALL SELECT 'organization_memberships', COUNT(*) FROM organization_memberships
UNION ALL SELECT 'membership_roles', COUNT(*) FROM membership_roles
UNION ALL SELECT 'role_permissions', COUNT(*) FROM role_permissions
UNION ALL SELECT 'daily_reports', COUNT(*) FROM daily_reports
UNION ALL SELECT 'daily_report_items', COUNT(*) FROM daily_report_items
UNION ALL SELECT 'report_comments', COUNT(*) FROM report_comments
UNION ALL SELECT 'tasks', COUNT(*) FROM tasks
UNION ALL SELECT 'weekly_plans', COUNT(*) FROM weekly_plans
UNION ALL SELECT 'ai_daily_report_runs', COUNT(*) FROM ai_daily_report_runs
UNION ALL SELECT 'ai_usage_records', COUNT(*) FROM ai_usage_records;
COMMIT;
-- Inspect SHOW DDL JOBS and SHOW FULL PROCESSLIST separately using the project's
-- read-only migration inspector; no competing DDL/migrator may be present.
