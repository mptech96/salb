<?php

namespace Tests\Support;

use Tests\TestCase;

abstract class PlatformControlPlaneTestCase extends TestCase
{
    protected function productionSource(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        self::assertFileExists($path, "Production source is missing: {$relativePath}");

        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    protected function pendingDefect(string $defectId, string $requiredBehavior): never
    {
        $this->markTestIncomplete("{$defectId}: {$requiredBehavior}");
    }
}

