<?php

namespace App\Services\AI;

use App\Models\MailMessage;
use App\Models\Organization;
use App\Models\ReportAutomationCycle;
use App\Models\ReportAutomationDelivery;
use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Services\AI\Daily\DailyDocumentService;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailOutbox;
use App\Services\Mail\ReportAutomationMail;
use App\Services\ReportClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Durable allocation and reconciliation only. AI runs on the existing queues. */
class ReportAutomation
{
    public function __construct(
        private readonly ReportClock $clock,
        private readonly ReportAutomationSchedule $schedule,
        private readonly WorkReportSnapshot $sources,
        private readonly WorkReportAnalysisService $analyses,
        private readonly MailAccess $access,
        private readonly MailOutbox $outbox,
    ) {}

    public function tick(): array
    {
        if (! config('report_automation.enabled')) {
            return ['status' => 'disabled', 'checked' => 0];
        }
        // A disabled/unmigrated rollout must never take down the web process.
        abort_unless(Schema::hasTable('report_automation_cycles'), 409, 'REPORT_AUTOMATION_MIGRATION_REQUIRED');
        $actor = User::query()->findOrFail((int) config('report_automation.actor_user_id'));
        $scope = $this->sources->scope($actor);
        abort_unless($this->access->membership($actor) !== null
            && collect($scope['restrictions'])->every(fn ($r) => $r[0] === 'organization'), 403, 'REPORT_AUTOMATION_COMPANY_ACCESS_REQUIRED');
        abort_unless(config('work_report_ai.enabled') && config('daily_report_ai.enabled'), 409, 'REPORT_AUTOMATION_AI_DISABLED');
        // Reject inline provider execution in a scheduler transaction.
        if (! app()->environment('testing')) {
            foreach (['work_report_ai', 'daily_report_ai'] as $feature) {
                $connection = config($feature.'.queue_connection') ?? (config('queue.default') === 'sync' ? 'sync' : $feature);
                abort_unless(config("queue.connections.$connection.driver") === 'database'
                    && config($feature.'.driver') === 'openai', 409, 'REPORT_AUTOMATION_BACKGROUND_AI_REQUIRED');
            }
        }
        $this->discover($actor, (int) $scope['organization_id']);
        $ids = ReportAutomationCycle::query()->where('organization_id', $scope['organization_id'])
            ->where('actor_user_id', $actor->id)->where('due_at', '<=', now())->where('next_check_at', '<=', now())
            ->whereNull('finalized_at')->orderBy('next_check_at')->orderBy('id')
            ->limit(max(1, min(50, (int) config('report_automation.max_cycles_per_tick'))))->pluck('id');
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $actor): void {
                    // Match the mail allocator's lock order. Parallel ticks cannot duplicate a cycle or email.
                    Organization::query()->whereKey($this->sources->membership($actor)->organization_id)->lockForUpdate()->firstOrFail();
                    $cycle = ReportAutomationCycle::query()->lockForUpdate()->findOrFail($id);
                    if ($cycle->finalized_at !== null || $cycle->next_check_at->isFuture()) {
                        return;
                    }
                    $cycle->update(['next_check_at' => now()->addMinutes(5), 'error_code' => null]);
                    $this->reconcile($actor, $cycle);
                });
            } catch (Throwable) {
                // Never log source text, tokens, addresses or DB exception traces in the scheduler.
                ReportAutomationCycle::query()->whereKey($id)->update(['error_code' => 'REPORT_AUTOMATION_CYCLE_REQUIRES_REVIEW', 'next_check_at' => now()->addMinutes(15)]);
            }
        }
        if (config('report_automation.mail_enabled')) {
            $this->outbox->recoverInterrupted();
            $messages = ReportAutomationDelivery::query()->whereIn('cycle_id', ReportAutomationCycle::query()->where('organization_id', $scope['organization_id'])->select('id'))
                ->whereNotNull('mail_message_id')->where('status', 'queued')->orderBy('updated_at')->limit(10)->get();
            foreach ($messages as $delivery) {
                $this->outbox->process($delivery->mail_message_id);
                $message = MailMessage::query()->findOrFail($delivery->mail_message_id);
                $delivery->update(['status' => $message->status, 'error_code' => $message->error_code]);
            }
        }

        return ['status' => 'ok', 'checked' => $ids->count()];
    }

    private function discover(User $actor, int $organization): void
    {
        $start = (string) config('report_automation.start_date');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start), 409, 'REPORT_AUTOMATION_START_DATE_REQUIRED');
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $start, config('reports.timezone'));
        abort_unless($first && $first->toDateString() === $start, 409, 'REPORT_AUTOMATION_INVALID_START_DATE');
        DB::transaction(function () use ($actor, $organization, $first): void {
            Organization::query()->whereKey($organization)->lockForUpdate()->firstOrFail();
            $last = ReportAutomationCycle::query()->where('organization_id', $organization)->max('end_date');
            $date = $last === null ? $first : CarbonImmutable::parse($last, config('reports.timezone'))->addDay();
            // At most 14 calendar days are allocated per wake-up; the database is the cursor.
            for ($i = 0; $i < 14 && $date->toDateString() <= $this->clock->businessDate(); $i++, $date = $date->addDay()) {
                if ($this->clock->cutoffForDate($date->toDateString())->isFuture()) {
                    break;
                }
                foreach ($this->schedule->kinds($date->toDateString()) as $kind) {
                    $times = $this->schedule->forDate($date->toDateString(), $kind);
                    ReportAutomationCycle::query()->firstOrCreate(['organization_id' => $organization, 'kind' => $kind, 'end_date' => $date->toDateString()],
                        ['actor_user_id' => $actor->id, ...$times, 'next_check_at' => $times['due_at']]);
                }
            }
        });
    }

    private function reconcile(User $actor, ReportAutomationCycle $cycle): void
    {
        $end = $cycle->end_date->toDateString();
        $days = $cycle->kind === 'weekly' ? 7 : 1;
        // Keep the existing daily normalization flow available in the daily tab,
        // including makeups; it never retries a compatible failed provider outcome.
        $this->sources->period($end, $days);
        $start = $cycle->end_date->subDays($days - 1);
        foreach ($this->clock->workingDatesBetween($start->toDateString(), $end) as $date) {
            app(DailyDocumentService::class)->requestDate($actor, $date);
        }
        $capture = $this->sources->capture($actor, $end, $days, null);
        if ($capture['snapshot']['reports'] === []) {
            $cycle->update(['status' => 'empty', 'finalized_at' => $cycle->source_closes_at->isPast() ? now() : null]);

            return;
        }
        if (($capture['snapshot']['daily_coverage']['missing_report_ids'] ?? []) !== []) {
            $cycle->update(['status' => 'waiting_daily', 'error_code' => 'REPORT_AUTOMATION_DAILY_NOT_READY']);

            return;
        }
        $settingsHash = WorkReportSnapshot::hash($this->analyses->settings());
        // A successful manual recovery/revision can satisfy this cycle, too.
        $run = WorkReportAnalysis::query()->where('organization_id', $cycle->organization_id)
            ->where('scope_hash', WorkReportSnapshot::hash($capture['snapshot']['scope']))
            ->where('source_hash', $capture['source_hash'])->where('days', $days)->whereDate('end_date', $end)
            ->whereNull('department_id')->where('status', 'completed')->latest('id')->get()
            ->first(fn ($r) => WorkReportSnapshot::hash($r->analysis_config) === $settingsHash);
        $run ??= $this->analyses->request($actor, $end, $days, null);
        $cycle->update(['analysis_id' => $run->id, 'status' => $run->status]);
        // No automatic retry/regenerate call, even when attempts remain.
        if ($run->status !== 'completed' || $run->result === null) {
            if (in_array($run->status, ['partial', 'failed'], true)) {
                $cycle->update(['error_code' => 'REPORT_AUTOMATION_AI_REQUIRES_REVIEW']);
            }

            return;
        }
        if ($run->source_hash !== $capture['source_hash'] || ! WorkReportAnalysisOutput::canExecute($run->analysis_config)) {
            $cycle->update(['error_code' => 'REPORT_AUTOMATION_STALE_RESULT']);

            return;
        }
        if (config('report_automation.mail_enabled') && $cycle->mail_due_at->lessThanOrEqualTo(now())) {
            $this->queueMail($actor, $cycle, $run);
        }
        // Continue looking for makeup reports after sending, without resending mail.
        if ($cycle->mail_frozen_at !== null && $cycle->source_closes_at->isPast() && in_array($cycle->mail_status, ['accepted', 'attention'], true)) {
            $cycle->update(['finalized_at' => now()]);
        }
    }

    private function queueMail(User $actor, ReportAutomationCycle $cycle, WorkReportAnalysis $run): void
    {
        if ($cycle->mail_frozen_at === null) {
            $ids = array_values(array_unique(config('report_automation.recipient_user_ids', [])));
            if ($ids === []) {
                $cycle->update(['error_code' => 'REPORT_AUTOMATION_RECIPIENTS_REQUIRED']);

                return;
            }
            abort_unless(count($ids) <= 50, 409, 'REPORT_AUTOMATION_TOO_MANY_RECIPIENTS');
            ReportAutomationDelivery::query()->where('cycle_id', $cycle->id)->whereNull('mail_message_id')->delete();
            $content = app(ReportAutomationMail::class)->build($run, $cycle->kind);
            $cycle->update(['mail_analysis_id' => $run->id, 'mail_content' => $content, 'mail_frozen_at' => now(), 'mail_status' => 'pending']);
            foreach ($ids as $id) {
                ReportAutomationDelivery::query()->create(['cycle_id' => $cycle->id, 'recipient_user_id' => $id, 'request_key' => (string) Str::uuid()]);
            }
        }
        foreach (ReportAutomationDelivery::query()->where('cycle_id', $cycle->id)->get() as $delivery) {
            if ($delivery->mail_message_id !== null) {
                $message = MailMessage::query()->findOrFail($delivery->mail_message_id);
                $delivery->update(['status' => $message->status, 'error_code' => $message->error_code]);

                continue;
            }
            if ($delivery->status !== 'pending') {
                continue;
            }
            try {
                app(ReportAutomationMailGuard::class)->authorizeRecipient($cycle, (int) $delivery->recipient_user_id);
            } catch (Throwable) {
                $delivery->update(['status' => 'blocked', 'error_code' => 'REPORT_AUTOMATION_RECIPIENT_ACCESS_REQUIRED']);

                continue;
            }
            // Savepoints isolate a recipient/connection failure from the other recipients.
            try {
                $messages = $this->outbox->queueMessages($actor, [['recipient_user_id' => $delivery->recipient_user_id, 'request_key' => $delivery->request_key]], $cycle->mail_content['subject'], $cycle->mail_content['body']);
                $delivery->update(['mail_message_id' => $messages[0]->id, 'status' => $messages[0]->status, 'error_code' => null]);
            } catch (Throwable) {
                $delivery->update(['error_code' => 'REPORT_AUTOMATION_MAIL_NOT_READY']);
            }
        }
        $states = ReportAutomationDelivery::query()->where('cycle_id', $cycle->id)->pluck('status');
        $cycle->update(['mail_status' => $states->every(fn ($s) => $s === 'accepted') ? 'accepted'
            : ($states->contains(fn ($s) => in_array($s, ['pending', 'queued', 'sending'], true)) ? 'pending' : 'attention')]);
        // No outbox allocation means delivery has not begun. Keep accepting makeups
        // while Gmail is disconnected, rather than freezing an obsolete morning draft.
        if (! ReportAutomationDelivery::query()->where('cycle_id', $cycle->id)->whereNotNull('mail_message_id')->exists()) {
            $cycle->update(['mail_analysis_id' => null, 'mail_content' => null, 'mail_frozen_at' => null, 'mail_status' => 'waiting', 'error_code' => 'REPORT_AUTOMATION_MAIL_NOT_READY']);
        }
    }
}
