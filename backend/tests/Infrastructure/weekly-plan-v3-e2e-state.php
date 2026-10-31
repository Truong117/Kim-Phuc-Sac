<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\WeeklyPlanV3IsolatedTestEnvironment;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$preflight = new Process([
    PHP_BINARY,
    $root.'/tests/Infrastructure/weekly-plan-v3-environment-preflight.php',
    'e2e',
    'verified-existing',
], $root);
$preflight->mustRun();

$planId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if ($planId === false || $planId === null || $planId < 1) {
    fwrite(STDERR, "Expected one positive synthetic plan ID.\n");
    exit(2);
}

$db = new PDO('sqlite:'.$root.'/'.WeeklyPlanV3IsolatedTestEnvironment::E2E_DATABASE);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$plan = $db->prepare('SELECT id, workflow_version, status, parse_status FROM weekly_plans WHERE id = ?');
$plan->execute([$planId]);
$record = $plan->fetch(PDO::FETCH_ASSOC);
$counts = [];
foreach ([
    'weekly_plan_employee_workflows' => 'weekly_plan_id',
    'weekly_plan_v3_review_batches' => 'weekly_plan_id',
    'weekly_plan_events' => 'weekly_plan_id',
] as $table => $column) {
    $query = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?");
    $query->execute([$planId]);
    $counts[$table] = (int) $query->fetchColumn();
}
$tasks = $db->prepare('SELECT COUNT(*) FROM tasks JOIN weekly_plan_entries ON tasks.weekly_plan_entry_id = weekly_plan_entries.id WHERE weekly_plan_entries.weekly_plan_id = ?');
$tasks->execute([$planId]);
$counts['tasks'] = (int) $tasks->fetchColumn();
$notifications = $db->prepare("SELECT COUNT(*) FROM user_notifications WHERE reference_type = 'TASK' AND reference_id IN (SELECT tasks.id FROM tasks JOIN weekly_plan_entries ON tasks.weekly_plan_entry_id = weekly_plan_entries.id WHERE weekly_plan_entries.weekly_plan_id = ?)");
$notifications->execute([$planId]);
$counts['user_notifications'] = (int) $notifications->fetchColumn();
$workflows = $db->prepare('SELECT assignee_id, state, state_version, content_version FROM weekly_plan_employee_workflows WHERE weekly_plan_id = ? ORDER BY assignee_id');
$workflows->execute([$planId]);
$batches = $db->prepare('SELECT id, stage FROM weekly_plan_v3_review_batches WHERE weekly_plan_id = ? ORDER BY id');
$batches->execute([$planId]);

echo json_encode([
    'plan' => $record ?: null,
    'counts' => $counts,
    'workflows' => $workflows->fetchAll(PDO::FETCH_ASSOC),
    'batches' => $batches->fetchAll(PDO::FETCH_ASSOC),
], JSON_THROW_ON_ERROR)."\n";
