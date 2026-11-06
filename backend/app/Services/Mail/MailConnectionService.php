<?php

namespace App\Services\Mail;

use App\Models\MailConnection;
use App\Models\MailMessage;
use App\Models\MailOauthAttempt;
use App\Models\Organization;
use App\Models\User;
use App\Services\Mail\Contracts\MailOAuthProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MailConnectionService
{
    public function __construct(private readonly MailAccess $access, private readonly MailConfiguration $configuration, private readonly MailOAuthProvider $oauth) {}

    public function start(User $actor): string
    {
        $membership = $this->access->require($actor, true);
        $this->configuration->requireReady();
        abort_unless(config('kps_mail.transport') === 'gmail_api', 503);
        $state = Str::random(64);
        $verifier = Str::random(96);
        DB::transaction(function () use ($membership, $actor, $state, $verifier): void {
            Organization::query()->whereKey($membership->organization_id)->lockForUpdate()->firstOrFail();
            $connection = MailConnection::query()->firstOrCreate(['organization_id' => $membership->organization_id], ['provider' => 'gmail_api']);
            MailOauthAttempt::query()->where('connection_id', $connection->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            MailOauthAttempt::query()->where('connection_id', $connection->id)->where('expires_at', '<', now()->subDay())->delete();
            MailOauthAttempt::query()->create([
                'connection_id' => $connection->id, 'user_id' => $actor->id, 'state_hash' => hash('sha256', $state),
                'configuration_hash' => $this->configuration->fingerprint(), 'connection_revision' => $connection->revision,
                'code_verifier' => $verifier, 'expires_at' => now()->addMinutes(10),
            ]);
        });

        return $this->oauth->authorizationUrl($state, $verifier);
    }

    // Callback is a Google top-level redirect. A durable one-time state binds it
    // to the initiating KPS actor/org without depending on cross-site cookies.
    public function complete(string $state, ?string $code): void
    {
        $this->configuration->requireReady();
        abort_unless(strlen($state) === 64, 400);
        $attempt = MailOauthAttempt::query()->where('state_hash', hash('sha256', $state))->first();
        abort_unless($attempt !== null && hash_equals($attempt->configuration_hash, $this->configuration->fingerprint()), 400);
        $claimed = MailOauthAttempt::query()->whereKey($attempt->id)->whereNull('consumed_at')->where('expires_at', '>', now())->update(['consumed_at' => now()]);
        abort_unless($claimed === 1 && is_string($code) && $code !== '' && strlen($code) <= 4096, 400);
        $actor = User::query()->findOrFail($attempt->user_id);
        $membership = $this->access->require($actor, true);
        $connection = MailConnection::query()->findOrFail($attempt->connection_id);
        abort_unless((int) $connection->organization_id === (int) $membership->organization_id && $connection->revision === $attempt->connection_revision, 409);
        $grant = $this->oauth->exchange($code, $attempt->code_verifier);
        DB::transaction(function () use ($connection, $attempt, $grant, $actor): void {
            $locked = MailConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $currentMembership = $this->access->require($actor, true);
            abort_unless($locked->revision === $attempt->connection_revision && (int) $locked->organization_id === (int) $currentMembership->organization_id, 409);
            // A second authorization may start while Google is responding.
            // Only the latest attempt may install credentials.
            abort_if(MailOauthAttempt::query()->where('connection_id', $locked->id)->where('id', '>', $attempt->id)->exists(), 409);
            abort_unless(hash_equals($attempt->configuration_hash, $this->configuration->fingerprint()), 409);
            $locked->fill([
                'provider' => 'gmail_api', 'status' => 'connected', 'email' => $grant['email'], 'provider_subject' => $grant['subject'],
                'configuration_hash' => $this->configuration->fingerprint(),
                'access_token' => $grant['access_token'], 'refresh_token' => $grant['refresh_token'],
                'expires_at' => now()->addSeconds($grant['expires_in']), 'connected_at' => now(), 'connected_by' => $actor->id,
                'revision' => $locked->revision + 1,
            ])->save();
        });
    }

    public function disconnect(User $actor): void
    {
        $membership = $this->access->require($actor, true);
        DB::transaction(function () use ($membership): void {
            Organization::query()->whereKey($membership->organization_id)->lockForUpdate()->firstOrFail();
            $connection = MailConnection::query()->where('organization_id', $membership->organization_id)->lockForUpdate()->first();
            if ($connection === null) {
                return;
            }
            $connection->fill(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'revision' => $connection->revision + 1])->save();
            MailOauthAttempt::query()->where('connection_id', $connection->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            MailMessage::query()->where('connection_id', $connection->id)->where('status', 'queued')->update(['status' => 'cancelled', 'error_code' => 'CONNECTION_DISCONNECTED']);
        });
    }
}
