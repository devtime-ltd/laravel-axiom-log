<?php

namespace DevtimeLtd\LaravelAxiomLog\Tests;

use DevtimeLtd\LaravelAxiomLog\AxiomLogServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AxiomLogServiceProvider::class];
    }
}
