<?php

namespace App\Services\Mail;

use App\Models\MailConnection;
use App\Models\MailMessage;
use App\Models\Organization;
use App\Models\ReportMailPreference;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class ReportMailPreferences
{
    public function schemaReady(): bool
    {
        return Schema::hasTable('report_mail_preferences') && Schema::hasTable('personal_weekly_mail_deliveries')
            && Schema::hasColumns('report_automation_cycles', ['personal_mail_next_check_at', 'personal_mail_finished_at']);
    }

    public function organization(User $viewer): int
    {
        $member = app(KpsMembershipResolver::class)->activeMembershipFor($viewer);
        abort_unless($member && app(AuthorizationService::class)->allows($viewer, 'reports.view'), 403);

        return (int) $member->organization_id;
    }

    public function sender(int $organization): ?User
    {
        $actor = User::query()->find((int) config('report_automation.actor_user_id'));

        return $actor && (int) app(MailAccess::class)->membership($actor)?->organization_id === $organization ? $actor : null;
    }

    public function verified(User $user, ?ReportMailPreference $preference): bool
    {
        return $preference !== null && $user->email_verified_at !== null && $preference->verified_at !== null
            && is_string($preference->verified_email_hash) && hash_equals($preference->verified_email_hash, ReportEmailPolicy::hash($user->email));
    }

    public function eligibility(User $user, ?ReportMailPreference $preference): string
    {
        return ReportEmailPolicy::reason($user->email) ?? (! $this->verified($user, $preference) ? 'unverified'
            : ($preference->weekly_enabled ? 'ready' : 'opted_out'));
    }

    public function status(User $viewer): array
    {
        $organization = $this->organization($viewer);
        $user = $viewer->fresh();
        $schema = $this->schemaReady();
        $pref = $schema ? ReportMailPreference::query()->where('organization_id', $organization)->where('user_id', $user->id)->first() : null;
        $message = $pref?->verification_mail_message_id ? MailMessage::query()->find($pref->verification_mail_message_id) : null;
        $ready = (bool) config('kps_mail.enabled') && (bool) config('kps_mail.send_enabled')
            && app(MailConfiguration::class)->ready() && $this->sender($organization) !== null;
        $connection = $ready ? MailConnection::query()->where('organization_id', $organization)->where('status', 'connected')->first() : null;
        $ready = $ready && $connection !== null && $connection->provider === config('kps_mail.transport')
            && hash_equals(app(MailConfiguration::class)->fingerprint(), (string) $connection->configuration_hash);

        return ['schema_ready' => $schema, 'email' => $user->email, 'eligibility' => $this->eligibility($user, $pref),
            'verified' => $this->verified($user, $pref), 'weekly_enabled' => $pref?->weekly_enabled ?? false,
            'auto_enabled' => (bool) config('report_automation.personal_weekly_mail_enabled'),
            'can_request' => $schema && $ready && ReportEmailPolicy::reason($user->email) === null,
            'verification_status' => $message?->status, 'request_after' => $pref?->verification_requested_at?->addMinute()->toIso8601String(),
            'verification_expires_at' => $pref?->verification_expires_at?->toIso8601String()];
    }

    public function request(User $viewer, string $key, string $expectedEmail): string
    {
        $organization = $this->organization($viewer);
        abort_unless($this->schemaReady(), 503, 'PERSONAL_MAIL_MIGRATION_REQUIRED');

        return DB::transaction(function () use ($viewer, $organization, $key, $expectedEmail): string {
            Organization::query()->whereKey($organization)->lockForUpdate()->firstOrFail();
            $user = User::query()->lockForUpdate()->findOrFail($viewer->id);
            abort_unless(hash_equals(ReportEmailPolicy::hash($user->email), ReportEmailPolicy::hash($expectedEmail)), 409, 'REPORT_EMAIL_CHANGED');
            if (ReportEmailPolicy::reason($user->email) !== null) {
                throw ValidationException::withMessages(['email' => 'Cần cập nhật email thật trong Tài khoản trước khi xác nhận nhận báo cáo.']);
            }
            $pref = ReportMailPreference::query()->firstOrCreate(['organization_id' => $organization, 'user_id' => $user->id]);
            if ($pref->verification_key === $key && $pref->verification_mail_message_id !== null) {
                abort_unless($pref->verification_expires_at?->isFuture(), 409, 'REPORT_EMAIL_REQUEST_EXPIRED');

                return $pref->verification_mail_message_id;
            }
            abort_if($this->verified($user, $pref), 409, 'REPORT_EMAIL_ALREADY_VERIFIED');
            abort_unless(! MailMessage::query()->where('organization_id', $organization)->where('request_key', $key)->exists(), 409, 'REPORT_EMAIL_REQUEST_EXPIRED');
            abort_if($pref->verification_requested_at?->greaterThan(now()->subMinute()), 429, 'REPORT_EMAIL_COOLDOWN');
            $actor = $this->sender($organization);
            abort_unless($actor, 503, 'REPORT_EMAIL_SENDER_REQUIRED');
            $origin = rtrim((string) config('kps_mail.frontend_url'), '/');
            abort_unless(filter_var($origin, FILTER_VALIDATE_URL) && parse_url($origin, PHP_URL_SCHEME) === 'https', 409, 'REPORT_EMAIL_FRONTEND_REQUIRED');
            $token = bin2hex(random_bytes(32));
            $pref->fill(['verification_key' => $key, 'verification_email_hash' => ReportEmailPolicy::hash($user->email),
                'verification_token_hash' => hash('sha256', $token), 'verification_expires_at' => now()->addHour(), 'verification_requested_at' => now()])->save();
            $body = "XÁC NHẬN NHẬN BÁO CÁO TUẦN CÁ NHÂN\n\nBạn đã yêu cầu nhận báo cáo tuần cá nhân từ KPS.\n\n"
                ."Đăng nhập đúng tài khoản KPS rồi bấm Xác nhận và nhận báo cáo tuần tại đường dẫn sau (có hiệu lực 60 phút):\n"
                .$origin.'/account#report-email-confirm='.$token."\n\nXác nhận này đồng thời đăng ký nhận báo cáo tuần của chính bạn. Bạn có thể tắt trong Tài khoản bất cứ lúc nào. Nếu không yêu cầu, hãy bỏ qua thư này.";
            $message = app(MailOutbox::class)->queueReportMail($actor, (int) $user->id, $key, '[KPS] Xác nhận email nhận báo cáo tuần', $body,
                ['purpose' => 'report_email_verification', 'preference_id' => (int) $pref->id, 'verification_key' => $key]);
            $pref->update(['verification_mail_message_id' => $message->id]);

            return $message->id;
        });
    }

    public function confirm(User $viewer, string $token): void
    {
        $organization = $this->organization($viewer);
        abort_unless($this->schemaReady(), 503, 'PERSONAL_MAIL_MIGRATION_REQUIRED');
        DB::transaction(function () use ($viewer, $organization, $token): void {
            Organization::query()->whereKey($organization)->lockForUpdate()->firstOrFail();
            $user = User::query()->lockForUpdate()->findOrFail($viewer->id);
            $pref = ReportMailPreference::query()->where('organization_id', $organization)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $pref || ! is_string($pref->verification_token_hash) || ! hash_equals($pref->verification_token_hash, hash('sha256', $token))
                || ! $pref->verification_expires_at?->isFuture() || ReportEmailPolicy::reason($user->email) !== null
                || ! hash_equals((string) $pref->verification_email_hash, ReportEmailPolicy::hash($user->email))) {
                throw ValidationException::withMessages(['token' => 'Liên kết không hợp lệ, đã dùng hoặc hết hạn. Hãy yêu cầu một thư xác nhận mới.']);
            }
            $user->forceFill(['email_verified_at' => now()])->save();
            $pref->update(['verified_email_hash' => ReportEmailPolicy::hash($user->email), 'verified_at' => now(),
                'weekly_enabled' => true, 'weekly_enabled_at' => now(), 'verification_token_hash' => null, 'verification_expires_at' => null]);
        });
    }

    public function update(User $viewer, bool $enabled): void
    {
        $organization = $this->organization($viewer);
        abort_unless($this->schemaReady(), 503, 'PERSONAL_MAIL_MIGRATION_REQUIRED');
        DB::transaction(function () use ($viewer, $organization, $enabled): void {
            Organization::query()->whereKey($organization)->lockForUpdate()->firstOrFail();
            $user = User::query()->lockForUpdate()->findOrFail($viewer->id);
            $pref = ReportMailPreference::query()->firstOrCreate(['organization_id' => $organization, 'user_id' => $viewer->id]);
            abort_unless(! $enabled || (ReportEmailPolicy::reason($user->email) === null && $this->verified($user, $pref)), 422, 'REPORT_EMAIL_VERIFICATION_REQUIRED');
            if ($pref->weekly_enabled !== $enabled) {
                $pref->update(['weekly_enabled' => $enabled, 'weekly_enabled_at' => $enabled ? now() : null]);
            }
        });
    }
}
