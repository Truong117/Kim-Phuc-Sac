<?php

namespace App\Services\Mail\Gmail;

use App\Models\MailConnection;
use App\Services\Mail\Contracts\MailTransport;
use App\Services\Mail\MailDeliveryException;
use App\Services\Mail\OutboundMail;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class GmailApiTransport implements MailTransport
{
    public function __construct(private readonly GmailTokenStore $tokens, private readonly GmailConfiguration $configuration) {}

    public function configured(): bool
    {
        return $this->configuration->ready();
    }

    public function configurationFingerprint(): string
    {
        return $this->configuration->fingerprint();
    }

    public function send(MailConnection $connection, OutboundMail $mail): string
    {
        $token = $this->tokens->accessToken($connection);
        $mime = (new Email)->from(new Address($mail->fromEmail, $mail->fromName))->to($mail->toEmail)->subject($mail->subject)->text($mail->text);
        $mime->getHeaders()->addIdHeader('Message-ID', $mail->id.'@kps.internal');
        try {
            // Never attach an automatic HTTP retry to a send: Gmail has no
            // idempotency key, so timeouts/5xx can already have accepted it.
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)->withOptions(['allow_redirects' => false])
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => rtrim(strtr(base64_encode($mime->toString()), '+/', '-_'), '=')]);
        } catch (Throwable) {
            throw new MailDeliveryException('PROVIDER_RESULT_UNKNOWN', 'unknown');
        }
        if ($response->status() === 401) {
            MailConnection::query()->whereKey($connection->id)->where('revision', $connection->revision)->update(['status' => 'reconnect_required', 'access_token' => null]);
            throw new MailDeliveryException('GMAIL_RECONNECT_REQUIRED', 'failed', true);
        }
        if ($response->status() === 429) {
            throw new MailDeliveryException('PROVIDER_RATE_LIMITED', 'retry');
        }
        if ($response->serverError() || $response->status() === 408) {
            throw new MailDeliveryException('PROVIDER_RESULT_UNKNOWN', 'unknown');
        }
        if (! $response->successful()) {
            throw new MailDeliveryException('PROVIDER_REJECTED');
        }
        $id = $response->json('id');
        if (! is_string($id) || $id === '' || strlen($id) > 255) {
            throw new MailDeliveryException('PROVIDER_RESULT_UNKNOWN', 'unknown');
        }

        return $id;
    }
}
