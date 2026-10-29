<?php

namespace App\Commands\Account;

use App\Classes\Utility;
use App\Http\Integrations\Winux\Requests\ClientRegistrationRequest;
use App\Http\Integrations\Winux\WinuxAuthConnector;
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
        $winuxAuthConnector = new WinuxAuthConnector();
        $registerRequest = new ClientRegistrationRequest();
        $registerResponse = $winuxAuthConnector->send($registerRequest);
        if ($registerResponse->failed()) {
            $this->warn('Failed to register this client with Winux.io. Error: ' . $registerResponse->status());
            // $this->warn($registerResponse->json());
            $this->error($registerResponse->json('message'));
            return false;
        }
        $diceware = $registerResponse->json('dice');
        $authorizeUrl = $registerResponse->json('url');

        info('Logging into to your Winux.io Account...');
        $autoOpen = confirm('Attempt to open the Winux.io login page in your default browser?', true);
        note("If the login page does not open, please visit the following URL in your browser:");
        info("\t\t" . $authorizeUrl . "\n");
        note('Verify the following codeword matches the one displayed on the authorize page:');
        info("\t\t" . $diceware . "\n");
        if ($autoOpen) {
            $result = Utility::openBrowser($authorizeUrl);
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
}
