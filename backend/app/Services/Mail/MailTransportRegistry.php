<?php

namespace App\Services\Mail;

use App\Services\Mail\Contracts\MailTransport;
use App\Services\Mail\Gmail\GmailApiTransport;
use Illuminate\Contracts\Container\Container;

class MailTransportRegistry
{
    /** @var array<string, class-string<MailTransport>> */
    private array $drivers = ['gmail_api' => GmailApiTransport::class];

    public function __construct(private readonly Container $container) {}

    /** Register an SMTP/other API adapter without changing outbox/domain code. */
    public function extend(string $name, string $transport): void
    {
        $this->drivers[$name] = $transport;
    }

    public function resolve(string $name): MailTransport
    {
        if (! isset($this->drivers[$name])) {
            throw new MailDeliveryException('MAIL_TRANSPORT_UNAVAILABLE');
        }

        return $this->container->make($this->drivers[$name]);
    }
}
