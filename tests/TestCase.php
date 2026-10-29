<?php

namespace Tests;

use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    public ?string $sandboxRoot = null;

    protected function setUp(): void
    {
        parent::setUp();

        // config/app.php hardcodes the environment; 'testing' makes Laravel Prompts fall back
        // to questions that expectsConfirmation()/expectsQuestion() can answer
        $this->app['env'] = 'testing';

        // Laravel Prompts keeps static state from the last command a test ran. Reset it, so prompts
        // called outside a command never wait on the terminal: they take their default answer
        \Laravel\Prompts\Prompt::fallbackWhen(false);
        \Laravel\Prompts\Prompt::interactive(false);

        \App\Classes\Platform::fakeEnvironment();
        \App\Classes\Platform::fake(null);
        \App\Classes\ServiceUser::fake(null);
    }

    protected function tearDown(): void
    {
        if ($this->sandboxRoot && is_dir($this->sandboxRoot)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->sandboxRoot);
        }

        parent::tearDown();
    }
}
