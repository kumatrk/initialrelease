<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

/**
 * Thrown when ReportingConcurrencyGuard has no free slot.
 */
final class ReportingBusyException extends \RuntimeException
{
    public function __construct(string $message = 'Reporting busy')
    {
        parent::__construct($message, 503);
    }
}
