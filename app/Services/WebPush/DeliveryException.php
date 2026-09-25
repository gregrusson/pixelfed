<?php

namespace App\Services\WebPush;

use RuntimeException;

/** Only fixed application categories belong here; never chain a transport exception. */
class DeliveryException extends RuntimeException
{
    public function __construct(public readonly string $category, public readonly bool $retryable = false)
    {
        parent::__construct('Web Push: '.$category);
    }

    public function __toString(): string
    {
        // Failed-job stores stringify exceptions. Omit traces, which may contain arguments.
        return $this->getMessage();
    }
}
