<?php

namespace App\Services\Mail\Contracts;

use App\Models\MailConnection;
use App\Services\Mail\OutboundMail;

interface MailTransport
{
    public function configured(): bool;

    /** Stable non-secret configuration identity used to reject stale connections. */
    public function configurationFingerprint(): string;

    /** Return the provider acceptance ID, not a delivery/read receipt. */
    public function send(MailConnection $connection, OutboundMail $mail): string;
}
