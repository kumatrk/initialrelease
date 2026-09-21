<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

/**
 * Pulls network spend on the Honeycomb cron dispatcher.
 */
interface CostSyncJob
{
    public function slug(): string;

    /**
     * @return array{ok: bool, message: string}
     */
    public function run(): array;
}
