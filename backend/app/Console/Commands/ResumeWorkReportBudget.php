<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Services\AI\WorkReportAnalysisService;
use App\Services\AI\WorkReportBudgetRecovery;
use Illuminate\Console\Command;
use Throwable;

class ResumeWorkReportBudget extends Command
{
    protected $signature = 'work-report-ai:resume-budget {analysis} {--evidence= : Reviewed JSON receipt of blocked attempts} {--apply : Apply the verified metadata repair} {--queue : Explicitly retry repaired units through the normal authorized flow}';

    protected $description = 'Plan or apply one evidence-bound recovery; never reset monthly spend or repeat paid/ambiguous attempts';

    public function handle(WorkReportBudgetRecovery $recovery, WorkReportAnalysisService $analyses): int
    {
        try {
            $path = (string) $this->option('evidence');
            if (! is_file($path) || filesize($path) > 65536 || ($this->option('queue') && ! $this->option('apply'))) {
                $this->error('Provide a reviewed JSON evidence file; --queue requires --apply.');

                return self::FAILURE;
            }
            $evidence = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $id = (int) $this->argument('analysis');
            $plan = $recovery->recover($id, $evidence);
            $this->info(json_encode($plan, JSON_THROW_ON_ERROR));
            if ($this->option('queue') && ! $plan['first_request_fits']) {
                $this->error('Current monthly budget cannot admit the first request. No repair or retry was executed.');

                return self::FAILURE;
            }
            if ($this->option('apply')) {
                $this->info(json_encode($recovery->recover($id, $evidence, true), JSON_THROW_ON_ERROR));
                if ($this->option('queue')) {
                    $run = WorkReportAnalysis::query()->findOrFail($id);
                    $analyses->retry(User::query()->findOrFail($run->requested_by_user_id), $id);
                    $this->info('Retry requested for analysis '.$id.'. Check its final status; queued is not completed.');
                }
            }

            return self::SUCCESS;
        } catch (Throwable) {
            // Never expose encrypted source/configuration or a database exception.
            $this->error('AI_BUDGET_RECOVERY_REFUSED: evidence, current state, saved contract or requester access did not pass verification.');

            return self::FAILURE;
        }
    }
}
