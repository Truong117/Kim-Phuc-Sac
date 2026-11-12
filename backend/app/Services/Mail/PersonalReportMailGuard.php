<?php

namespace App\Services\Mail;

use App\Models\MailMessage;
use App\Models\PersonalWeeklyMailDelivery;
use App\Models\ReportAutomationCycle;
use App\Models\ReportMailPreference;
use App\Models\User;
use App\Services\AI\PersonalWeeklyReport;
use Throwable;

final class PersonalReportMailGuard
{
    public function canProcess(MailMessage $message): bool
    {
        $context = $message->payload['report_context'] ?? null;
        if ($context === null) {
            return true;
        }
        if (($context['purpose'] ?? '') === 'personal_weekly_report'
            && (! config('report_automation.enabled') || ! config('report_automation.personal_weekly_mail_enabled'))) {
            return false;
        }
        try {
            $preferences = app(ReportMailPreferences::class);
            abort_unless($preferences->schemaReady(), 403);
            $user = User::query()->findOrFail($message->recipient_user_id);
            abort_unless($preferences->organization($user) === (int) $message->organization_id, 403);
            abort_unless(ReportEmailPolicy::reason($user->email) === null, 403);
            $pref = ReportMailPreference::query()->where('organization_id', $message->organization_id)->where('user_id', $user->id)->firstOrFail();
            if ($context['purpose'] === 'report_email_verification') {
                abort_unless((int) ($context['preference_id'] ?? 0) === (int) $pref->id
                    && $pref->verification_mail_message_id === $message->id && $pref->verification_key === ($context['verification_key'] ?? null)
                    && $pref->verification_token_hash !== null && $pref->verification_expires_at?->isFuture()
                    && hash_equals((string) $pref->verification_email_hash, ReportEmailPolicy::hash($user->email)), 403);
            } elseif ($context['purpose'] === 'personal_weekly_report') {
                $delivery = PersonalWeeklyMailDelivery::query()->where('organization_id', $message->organization_id)
                    ->where('user_id', $user->id)->findOrFail((int) ($context['delivery_id'] ?? 0));
                $cycle = ReportAutomationCycle::query()->findOrFail($delivery->cycle_id);
                abort_unless($delivery->mail_message_id === $message->id && $cycle->kind === 'weekly'
                    && (int) $cycle->organization_id === (int) $message->organization_id
                    && (int) $cycle->actor_user_id === (int) $message->created_by
                    && (int) config('report_automation.actor_user_id') === (int) $message->created_by
                    && $cycle->mail_due_at->lessThanOrEqualTo(now())
                    && $delivery->end_date->toDateString() >= PersonalWeeklyMail::startDate()
                    && $preferences->eligibility($user, $pref) === 'ready' && $pref->weekly_enabled_at?->lessThanOrEqualTo($cycle->mail_due_at)
                    && hash_equals((string) $delivery->email_hash, ReportEmailPolicy::hash($user->email)), 403);
                $report = app(PersonalWeeklyReport::class)->forMail($user, $delivery->end_date->toDateString());
                abort_unless($report['status'] === 'completed' && ! $report['source_changed'] && ! $report['updating']
                    && ($report['_analysis_id'] ?? null) === (int) $delivery->analysis_id
                    && (! $report['is_mock'] || app()->environment('testing')), 403);
            } else {
                abort(403);
            }
        } catch (Throwable) {
            throw new MailDeliveryException('PERSONAL_REPORT_MAIL_ACCESS_CHANGED');
        }

        return true;
    }
}
