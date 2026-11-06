<?php

namespace App\Services\Mail;

use RuntimeException;

// Never attach provider exceptions: they can carry tokens, MIME or recipients.
class MailDeliveryException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly string $outcome = 'failed', public readonly bool $reconnect = false)
    {
        parent::__construct($errorCode);
    }
}
