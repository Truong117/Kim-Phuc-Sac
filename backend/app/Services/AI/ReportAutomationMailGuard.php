<?php

namespace App\Services\AI;

use App\Models\MailMessage;
use App\Models\ReportAutomationCycle;
use App\Models\ReportAutomationDelivery;
use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailDeliveryException;
use App\Services\Mail\MailOutbox;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReportAutomationMailGuard
{
    public function authorizeRecipient(ReportAutomationCycle $cycle, int $userId): void
    {
        abort_unless(in_array($userId, config('report_automation.recipient_user_ids', []), true), 403);
        $user = app(MailOutbox::class)->recipient((int) $cycle->organization_id, $userId);
        $member = app(MailAccess::class)->require($user);
        abort_unless((int) $member->organization_id === (int) $cycle->organization_id, 403);
        app(WorkReportAnalysisService::class)->show($user, (int) $cycle->mail_analysis_id);
    }

    /** Pause existing automatic mail on switch-off; recheck scope before every provider attempt. */
    public function canProcess(MailMessage $message): bool
    {
        if (! Schema::hasTable('report_automation_deliveries')) {
            return true;
        }
        $delivery = ReportAutomationDelivery::query()->where('mail_message_id', $message->id)->first();
        if ($delivery === null) {
            return true;
        }
        if (! config('report_automation.enabled') || ! config('report_automation.mail_enabled')) {
            return false;
        }
        try {
            $cycle = ReportAutomationCycle::query()->findOrFail($delivery->cycle_id);
            abort_unless((int) config('report_automation.actor_user_id') === (int) $cycle->actor_user_id, 403);
            $actor = User::query()->findOrFail($cycle->actor_user_id);
            app(WorkReportAnalysisService::class)->show($actor, (int) $cycle->mail_analysis_id);
            $this->authorizeRecipient($cycle, (int) $message->recipient_user_id);
        } catch (Throwable) {
            throw new MailDeliveryException('REPORT_AUTOMATION_RECIPIENT_ACCESS_REVOKED');
        }

        return true;
    }
}
