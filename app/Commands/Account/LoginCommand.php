<?php

namespace App\Commands\Account;

use App\Classes\Utility;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\info;

class LoginCommand extends Command
{
    protected $loggedIn = false;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'login';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Attempts to login to your Winux.io Account.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // $loginUuid = Str::uuid()->toString();
        // $loginUuid = (string)Str::ulid();
        $loginUuid = Str::ulid();
        // dd($loginUuid);
        $loginUrl = 'https://winux.io/login/cli';
        if (App::environment('development')) {
            // $loginUrl = 'https://winux-app.local.winux.io/login/cli';
            $loginUrl = 'https://winux-app.local.winux.io/api/client/register/' . $loginUuid;
            dd($loginUrl);
        }
        info('Logging into to your Winux.io Account...');
        $autoOpen = confirm('Attempt to open the Winux.io login page in your default browser?', true);
        note("If the login page does not open, please visit $loginUrl in your browser.");
        if ($autoOpen) {
            $result = Utility::openBrowser($loginUrl);
            if ($result !== true) {
                alert('Failed to open the login page in your browser.');
                error("Error: '$result'");
            }
        }

        spin(function () {
            while (!$this->loggedIn) {
                // TODO: Check if the user has authorized this client
                sleep(1);
            }
        }, 'Waiting for you to login to your Winux.io Account...');

        return self::SUCCESS;
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
