<?php

namespace App\Services\Mail;

use App\Models\MailConnection;
use App\Models\MailMessage;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class MailOutbox
{
    public function __construct(private readonly MailAccess $access, private readonly MailConfiguration $configuration, private readonly MailTransportRegistry $transports) {}

    public function queueTest(User $actor, int $recipientId, string $requestKey): MailMessage
    {
        $membership = $this->access->require($actor);
        $this->configuration->requireReady();
        abort_unless(config('kps_mail.send_enabled'), 503, 'KPS_MAIL_SEND_DISABLED');

        return DB::transaction(function () use ($actor, $recipientId, $requestKey, $membership): MailMessage {
            Organization::query()->whereKey($membership->organization_id)->lockForUpdate()->firstOrFail();
            $existing = MailMessage::query()->where('organization_id', $membership->organization_id)->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                abort_unless((int) $existing->created_by === (int) $actor->id && (int) $existing->recipient_user_id === $recipientId && $existing->kind === 'test', 409);

                return $existing;
            }
            $recipient = $this->recipient((int) $membership->organization_id, $recipientId);
            if (! $this->allowedTestEmail($recipient->email)) {
                throw ValidationException::withMessages(['recipient_user_id' => 'Nhân viên này chưa được cho phép nhận thư thử.']);
            }
            $connection = MailConnection::query()->where('organization_id', $membership->organization_id)->where('status', 'connected')->first();
            abort_unless($connection !== null && $connection->provider === config('kps_mail.transport')
                && hash_equals($this->configuration->fingerprint(), (string) $connection->configuration_hash), 409, 'GMAIL_NOT_CONNECTED');

            return MailMessage::query()->create([
                'organization_id' => $membership->organization_id, 'connection_id' => $connection->id, 'created_by' => $actor->id,
                'recipient_user_id' => $recipientId, 'request_key' => $requestKey, 'kind' => 'test', 'provider' => $connection->provider,
                'connection_revision' => $connection->revision, 'status' => 'queued', 'available_at' => now(),
                'payload' => ['to' => $recipient->email, 'subject' => '[KPS] Kiểm tra kết nối gửi mail', 'text' => 'Chào '.$recipient->name.",\n\nĐây là thư kiểm tra kết nối gửi mail từ hệ thống KPS.\n\nKim Phúc Sắc – KPS"],
            ]);
        });
    }

    public function process(string $id): void
    {
        if (! config('kps_mail.enabled') || ! config('kps_mail.send_enabled') || ! $this->configuration->ready()) {
            return;
        }
        $message = DB::transaction(function () use ($id): ?MailMessage {
            $message = MailMessage::query()->whereKey($id)->lockForUpdate()->first();
            if ($message === null || $message->status !== 'queued' || $message->available_at->isFuture()) {
                return null;
            }
            $message->fill(['status' => 'sending', 'started_at' => now(), 'attempts' => $message->attempts + 1])->save();

            return $message;
        });
        if ($message === null) {
            return;
        }
        try {
            $actor = User::query()->find($message->created_by);
            $membership = $actor === null ? null : $this->access->membership($actor);
            if ($membership === null || (int) $membership->organization_id !== (int) $message->organization_id) {
                throw new MailDeliveryException('SENDER_ACCESS_REVOKED');
            }
            $recipient = $this->recipient((int) $message->organization_id, (int) $message->recipient_user_id);
            $payload = $message->payload;
            if ($recipient->email !== $payload['to'] || ($message->kind === 'test' && ! $this->allowedTestEmail($recipient->email))) {
                throw new MailDeliveryException('RECIPIENT_CHANGED_OR_NOT_ALLOWED');
            }
            $connection = MailConnection::query()->findOrFail($message->connection_id);
            if ((int) $connection->organization_id !== (int) $message->organization_id || $connection->revision !== $message->connection_revision
                || ! hash_equals($this->configuration->fingerprint(), (string) $connection->configuration_hash)
                || $connection->status !== 'connected' || $connection->provider !== $message->provider) {
                throw new MailDeliveryException('CONNECTION_CHANGED');
            }
            $transport = $this->transports->resolve($message->provider);
            $receipt = $transport->send($connection, new OutboundMail($message->id, $connection->email, (string) config('kps_mail.from_name'), $payload['to'], $payload['subject'], $payload['text']));
            $message->fill(['status' => 'accepted', 'provider_message_id' => $receipt, 'accepted_at' => now(), 'error_code' => null])->save();
        } catch (MailDeliveryException $e) {
            $retry = $e->outcome === 'retry' && $message->attempts < 3;
            $message->fill(['status' => $retry ? 'queued' : ($e->outcome === 'unknown' ? 'unknown' : 'failed'), 'error_code' => $e->errorCode, 'available_at' => now()->addMinutes($message->attempts)])->save();
        } catch (ValidationException) {
            $message->fill(['status' => 'cancelled', 'error_code' => 'RECIPIENT_INACTIVE'])->save();
        } catch (Throwable) {
            // If we cannot prove that no send occurred, require manual review.
            $message->fill(['status' => 'unknown', 'error_code' => 'RESULT_REQUIRES_REVIEW'])->save();
        }
    }

    public function recoverInterrupted(): int
    {
        return MailMessage::query()->where('status', 'sending')->where('started_at', '<', now()->subMinutes(5))
            ->update(['status' => 'unknown', 'error_code' => 'PROCESS_INTERRUPTED']);
    }

    public function recipient(int $organizationId, int $userId): User
    {
        $member = OrganizationMembership::query()->where('organization_id', $organizationId)->where('user_id', $userId)->where('is_default', true)->where('is_active', true)->with('user')->first();
        if ($member === null || $member->user === null || filter_var($member->user->email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['recipient_user_id' => 'Chọn nhân viên đang hoạt động và có email hợp lệ trong KPS.']);
        }

        return $member->user;
    }

    public function allowedTestEmail(string $email): bool
    {
        return in_array(mb_strtolower($email), array_map('mb_strtolower', config('kps_mail.test_recipients', [])), true);
    }
}
