<?php

namespace App\Services\Mail;

final readonly class OutboundMail
{
    public function __construct(public string $id, public string $fromEmail, public string $fromName, public string $toEmail, public string $subject, public string $text) {}
}
