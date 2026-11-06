<?php

namespace App\Services\Mail;

class MailConfiguration
{
    public function __construct(private readonly MailTransportRegistry $transports) {}

    public function ready(): bool
    {
        try {
            return $this->transports->resolve((string) config('kps_mail.transport'))->configured();
        } catch (MailDeliveryException) {
            return false;
        }
    }

    public function requireReady(): void
    {
        abort_unless(config('kps_mail.enabled') && $this->ready(), 503, 'KPS_MAIL_NOT_CONFIGURED');
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            config('app.env'), config('kps_mail.transport'),
            $this->transports->resolve((string) config('kps_mail.transport'))->configurationFingerprint(),
        ], JSON_THROW_ON_ERROR));
    }
}
