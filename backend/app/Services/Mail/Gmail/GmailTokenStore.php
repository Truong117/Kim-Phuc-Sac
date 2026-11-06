<?php

namespace App\Services\Mail\Gmail;

use App\Models\MailConnection;
use App\Services\Mail\Contracts\MailOAuthProvider;
use App\Services\Mail\MailDeliveryException;
use Illuminate\Support\Facades\DB;

class GmailTokenStore
{
    public function __construct(private readonly MailOAuthProvider $oauth, private readonly GmailConfiguration $configuration) {}

    private function configurationHash(): string
    {
        return hash('sha256', json_encode([config('app.env'), 'gmail_api', $this->configuration->fingerprint()], JSON_THROW_ON_ERROR));
    }

    public function accessToken(MailConnection $snapshot): string
    {
        try {
            return DB::transaction(function () use ($snapshot): string {
                // Serialize refreshes across HTTP/CLI workers; no send inside this transaction.
                $connection = MailConnection::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                if ($connection->status !== 'connected' || $connection->revision !== $snapshot->revision
                    || ! hash_equals($this->configurationHash(), (string) $connection->configuration_hash)
                    || $connection->provider !== 'gmail_api' || mb_strtolower((string) $connection->email) !== mb_strtolower((string) config('kps_mail.gmail.expected_email'))) {
                    throw new MailDeliveryException('CONNECTION_CHANGED');
                }
                if ($connection->expires_at?->isAfter(now()->addMinute()) && is_string($connection->access_token) && $connection->access_token !== '') {
                    return $connection->access_token;
                }
                if (! is_string($connection->refresh_token) || $connection->refresh_token === '') {
                    throw new MailDeliveryException('GMAIL_RECONNECT_REQUIRED', 'failed', true);
                }
                $tokens = $this->oauth->refresh($connection->refresh_token);
                $connection->fill(['access_token' => $tokens['access_token'], 'expires_at' => now()->addSeconds($tokens['expires_in'])])->save();

                return $tokens['access_token'];
            });
        } catch (MailDeliveryException $e) {
            if ($e->reconnect) {
                MailConnection::query()->whereKey($snapshot->id)->where('revision', $snapshot->revision)->where('status', 'connected')->update(['status' => 'reconnect_required', 'access_token' => null]);
            }
            throw $e;
        }
    }
}
