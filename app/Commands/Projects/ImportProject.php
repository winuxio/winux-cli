<?php

namespace App\Commands\Projects;

use App\Classes\Project;
use App\Classes\Winux;
use App\Services\Nginx;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{info, alert, confirm};

class ImportProject extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'project:import {projectName : The name of the project in the local directory that will have its infrastructure configured}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Import an existing project in the local directory and configure its infrastructure';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $projectName = $this->argument('projectName');

        Winux::SetupEnvironment();

        $project = Project::create($projectName);

        info("Importing project '{$project->name}' at '{$project->path}'");

        info('Performing infrastructure configuration...');

        if (!$project->setupNginx()) {
            alert('Failed to create the nginx site.');
            return Command::FAILURE;
        }

        Nginx::make()->reload();

        if (!$project->setupDatabase()) {
            alert('Failed to create all required postgres resources.');
            return Command::FAILURE;
        }

        if (!$project->configureEnv()) {
            alert('Failed to create the .env file.');
            return Command::FAILURE;
        }

        info("{$project->name}'s .env file updated successfully!");

        $this->title('Project imported successfully!');

        if (confirm('Do you want to open the project in VS Code?', true)) {
            $project->openEditor();
        }

        if (confirm('Do you want to open the project in your browser?', true)) {
            $project->openBrowser();
        }

        info("{$project->name} is live at https://{$project->getDomain()}");
        info("Switch into the new project directory with `cd $projectName` if needed.");
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
