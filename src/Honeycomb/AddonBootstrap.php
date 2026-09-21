<?php

declare(strict_types=1);

namespace SimpleKuma\Honeycomb;

interface AddonBootstrap
{
    public function register(HoneycombKernel $kernel): void;
}
