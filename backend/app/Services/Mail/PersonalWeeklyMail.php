<?php

namespace App\Services\Mail;

use App\Models\MailMessage;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PersonalWeeklyMailDelivery;
use App\Models\ReportAutomationCycle;
use App\Models\ReportMailPreference;
use App\Models\User;
use App\Services\AI\PersonalWeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class PersonalWeeklyMail
{
    public static function startDate(): string
    {
        $start = (string) config('report_automation.personal_weekly_mail_start_date');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/D', $start), 409, 'PERSONAL_MAIL_START_DATE_REQUIRED');
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $start);
        abort_unless($date && $date->toDateString() === $start && $date->isSaturday(), 409, 'PERSONAL_MAIL_START_DATE_REQUIRED');

        return $start;
    }

    public function tick(User $actor, int $organization): void
    {
        $preferences = app(ReportMailPreferences::class);
        if (! $preferences->schemaReady()) {
            return;
        }
        // Explicit verification requests can finish while weekly delivery is still switched off.
        $verificationIds = ReportMailPreference::query()->where('organization_id', $organization)
            ->whereNotNull('verification_mail_message_id')->pluck('verification_mail_message_id');
        if ($verificationIds->isNotEmpty()) {
            $this->process(MailMessage::query()->whereIn('id', $verificationIds)->where('status', 'queued')->where('available_at', '<=', now())->limit(10)->pluck('id')->all(), $organization);
        }
        if (! config('report_automation.personal_weekly_mail_enabled')) {
            return;
        }
        $start = self::startDate();
        $cycles = ReportAutomationCycle::query()->where('organization_id', $organization)->where('actor_user_id', $actor->id)
            ->where('kind', 'weekly')->whereDate('end_date', '>=', $start)->where('mail_due_at', '<=', now())
            ->whereNull('personal_mail_finished_at')->where(fn ($q) => $q->whereNull('personal_mail_next_check_at')->orWhere('personal_mail_next_check_at', '<=', now()))
            ->orderBy('personal_mail_next_check_at')->orderBy('id')->limit(3)->get();
        foreach ($cycles as $cycle) {
            DB::transaction(function () use ($actor, $organization, $cycle): void {
                Organization::query()->whereKey($organization)->lockForUpdate()->firstOrFail();
                $locked = ReportAutomationCycle::query()->lockForUpdate()->findOrFail($cycle->id);
                if ($locked->personal_mail_finished_at !== null || $locked->personal_mail_next_check_at?->isFuture()) {
                    return;
                }
                $locked->update(['personal_mail_next_check_at' => now()->addMinutes(5)]);
                $members = OrganizationMembership::query()->where('organization_id', $organization)->where('is_default', true)->where('is_active', true)
                    ->with('user')->orderBy('user_id')->get();
                // Allocate durable rows first; each later tick handles only a bounded slice.
                foreach ($members as $member) {
                    PersonalWeeklyMailDelivery::query()->firstOrCreate(['organization_id' => $organization, 'user_id' => $member->user_id, 'end_date' => $locked->end_date->toDateString()],
                        ['cycle_id' => $locked->id, 'request_key' => (string) Str::uuid()]);
                }
                $deliveries = PersonalWeeklyMailDelivery::query()->where('cycle_id', $locked->id)->whereNull('mail_message_id')
                    ->whereIn('status', ['waiting_analysis', 'waiting_mail'])->orderBy('updated_at')->limit(50)->get();
                foreach ($deliveries as $delivery) {
                    $this->prepare($actor, $locked, $delivery);
                }
            });
        }
        $ids = PersonalWeeklyMailDelivery::query()->where('organization_id', $organization)->where('status', 'queued')
            ->whereNotNull('mail_message_id')->orderBy('updated_at')->limit(10)->pluck('mail_message_id')->all();
        $this->process($ids, $organization);
        foreach ($cycles as $cycle) {
            $pending = PersonalWeeklyMailDelivery::query()->where('cycle_id', $cycle->id)->whereIn('status', ['waiting_analysis', 'waiting_mail', 'queued', 'sending'])->exists();
            if (! $pending) {
                $cycle->update(['personal_mail_finished_at' => now()]);
            }
        }
    }

    private function prepare(User $actor, ReportAutomationCycle $cycle, PersonalWeeklyMailDelivery $delivery): void
    {
        $preferences = app(ReportMailPreferences::class);
        try {
            $user = User::query()->findOrFail($delivery->user_id);
            abort_unless($preferences->organization($user) === (int) $cycle->organization_id, 403);
            $pref = ReportMailPreference::query()->where('organization_id', $cycle->organization_id)->where('user_id', $user->id)->first();
            $eligibility = $preferences->eligibility($user, $pref);
            if ($eligibility !== 'ready' || ! $pref?->weekly_enabled_at?->lessThanOrEqualTo($cycle->mail_due_at)) {
                $delivery->update(['status' => 'skipped', 'error_code' => 'PERSONAL_MAIL_'.strtoupper($eligibility === 'ready' ? 'not_subscribed_at_due' : $eligibility)]);

                return;
            }
            $report = app(PersonalWeeklyReport::class)->forMail($user, $delivery->end_date->toDateString());
            if ($report['status'] === 'no_data') {
                $delivery->update(['status' => 'skipped', 'error_code' => 'PERSONAL_MAIL_NO_REPORT']);

                return;
            }
            if ($report['status'] !== 'completed' || $report['source_changed'] || $report['updating'] || ($report['is_mock'] && ! app()->environment('testing'))) {
                $delivery->update(['status' => 'waiting_analysis', 'error_code' => 'PERSONAL_MAIL_ANALYSIS_NOT_READY']);

                return;
            }
            $content = $this->content($report);
            $delivery->update(['analysis_id' => $report['_analysis_id'], 'email_hash' => ReportEmailPolicy::hash($user->email)]);
            $message = app(MailOutbox::class)->queueReportMail($actor, (int) $user->id, $delivery->request_key, $content['subject'], $content['body'],
                ['purpose' => 'personal_weekly_report', 'delivery_id' => (int) $delivery->id]);
            $delivery->update(['mail_message_id' => $message->id, 'status' => $message->status, 'error_code' => null]);
        } catch (Throwable $e) {
            $denied = $e instanceof HttpExceptionInterface && $e->getStatusCode() === 403;
            $delivery->update(['status' => $denied ? 'skipped' : 'waiting_mail', 'error_code' => $denied ? 'PERSONAL_MAIL_ACCESS_REVOKED' : 'PERSONAL_MAIL_NOT_READY']);
        }
    }

    private function process(array $ids, int $organization): void
    {
        app(MailOutbox::class)->recoverInterrupted();
        foreach ($ids as $id) {
            app(MailOutbox::class)->process($id);
        }
        // Includes accepted/unknown results processed by the general mail command.
        foreach (PersonalWeeklyMailDelivery::query()->where('organization_id', $organization)->whereNotNull('mail_message_id')->whereIn('status', ['queued', 'sending'])->get() as $delivery) {
            $message = MailMessage::query()->find($delivery->mail_message_id);
            if ($message) {
                $delivery->update(['status' => $message->status, 'error_code' => $message->error_code]);
            }
        }
    }

    public function content(array $report): array
    {
        $origin = rtrim((string) config('kps_mail.frontend_url'), '/');
        abort_unless(filter_var($origin, FILTER_VALIDATE_URL) && parse_url($origin, PHP_URL_SCHEME) === 'https', 409);
        $period = CarbonImmutable::parse($report['period']['start_date'])->format('d/m/Y').' – '.CarbonImmutable::parse($report['period']['end_date'])->format('d/m/Y');
        $employee = $report['employee'];
        $result = $employee['result'];
        $chunks = ['BÁO CÁO TUẦN CÁ NHÂN '.$period, 'Chào '.$employee['name'].','];
        $sections = [
            ['Tổng hợp từ báo cáo', array_map(fn ($g) => $g['title'].': '.$g['text'], $employee['work_groups'])],
            ['Cần làm rõ', array_column($result['uncertainties'], 'text')],
            ['Nhận xét', array_column(array_merge($result['overview'], $result['highlights'], $result['ongoing'], $result['attention'], $result['support']), 'text')],
            ['Đề xuất hỗ trợ', array_column($result['recommendations'], 'text')],
        ];
        foreach ($sections as [$title, $lines]) {
            $lines = array_slice(array_values(array_unique($lines)), 0, 5);
            if ($lines !== []) {
                $chunks[] = $title."\n".implode("\n", array_map(fn ($line) => '- '.Str::limit(preg_replace('/\s+/u', ' ', $line), 600, '…'), $lines));
            }
        }
        $chunks[] = 'Xem bản đầy đủ và nguồn báo cáo (cần đăng nhập): '.$origin.'/reports/my-weekly-summary?end_date='.$report['period']['end_date'];
        $chunks[] = 'AI tổng hợp từ báo cáo đã chốt; hoàn thành là trạng thái tự báo cáo. Email là bản rút gọn tại thời điểm gửi. Bạn có thể tắt nhận báo cáo tuần trong Tài khoản.';

        return ['subject' => '[KPS] Báo cáo tuần của bạn '.$period, 'body' => implode("\n\n", $chunks)];
    }
}
