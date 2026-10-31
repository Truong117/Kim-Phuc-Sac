<?php

namespace Tests\Support;

use PDO;
use Throwable;

class CommitFaultPdo extends PDO
{
    public int $commitAttempts = 0;

    private ?Throwable $failure = null;

    private bool $commitBeforeFailure = false;

    public function failNextCommit(Throwable $error, bool $commitBeforeFailure = false): void
    {
        $this->failure = $error;
        $this->commitBeforeFailure = $commitBeforeFailure;
        $this->commitAttempts = 0;
    }

    public function commit(): bool
    {
        $this->commitAttempts++;
        if ($this->failure !== null) {
            $error = $this->failure;
            $this->failure = null;
            if ($this->commitBeforeFailure) {
                parent::commit();
            }
            throw $error;
        }

        return parent::commit();
    }
}
