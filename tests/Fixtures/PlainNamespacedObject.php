<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog\Tests\Fixtures;

class PlainNamespacedObject
{
    public function __construct(public readonly string $value) {}
}
