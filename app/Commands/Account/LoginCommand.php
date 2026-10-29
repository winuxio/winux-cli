<?php

namespace App\Commands\Account;

use App\Classes\Utility;
use App\Http\Integrations\Winux\Requests\ClientRegistrationRequest;
use App\Http\Integrations\Winux\WinuxAuthConnector;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\alert;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\info;

/**
 * Winux.io accounts aren't live yet, so this command is hidden (config/commands.php) and stops
 * right away unless WINUX_ACCOUNT_LOGIN is set. The registration flow is kept for when they are.
 */
class LoginCommand extends Command
{
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
     */
    public function handle(): int
    {
        if (!getenv('WINUX_ACCOUNT_LOGIN')) {
            error('Logging in to a Winux.io account is not available yet.');
            return self::FAILURE;
        }

        $winuxAuthConnector = new WinuxAuthConnector();
        $registerRequest = new ClientRegistrationRequest();
        $registerResponse = $winuxAuthConnector->send($registerRequest);
        if ($registerResponse->failed()) {
            $this->warn('Failed to register this client with Winux.io. Error: ' . $registerResponse->status());
            $this->error((string) $registerResponse->json('message'));
            return self::FAILURE;
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

        // TODO: Poll Winux.io until the user has authorized this client, then store the token.
        // Until that endpoint exists, stop here instead of waiting forever.
        error('Waiting for the authorization is not implemented yet.');
        return self::FAILURE;
    }
}
