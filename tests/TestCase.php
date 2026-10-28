<?php

namespace Tests;

use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    public ?string $sandboxRoot = null;

    protected function tearDown(): void
    {
        if ($this->sandboxRoot && is_dir($this->sandboxRoot)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->sandboxRoot);
        }

        parent::tearDown();
    }
}
