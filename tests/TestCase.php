<?php

namespace DevtimeLtd\LaravelAxiomLog\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \DevtimeLtd\LaravelAxiomLog\LaravelAxiomLogServiceProvider::class,
        ];
    }
}
