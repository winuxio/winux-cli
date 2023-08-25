<?php

namespace App\Commands\Projects;

use App\Classes\Certificate;
use App\Classes\Site;
use App\Services\Nginx;
use App\Services\Postgres;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{alert, error, info, confirm, text, warning};

class DeleteProject extends Command
{
	/**
	 * The signature of the command.
	 *
	 * @var string
	 */
	protected $signature = 'project:delete {projectName : The name of your new project that will be deleted from the local directory}'; // {--type=laravel}';

	/**
	 * The description of the command.
	 *
	 * @var string
	 */
	protected $description = 'Delete a project';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle()
	{
		$projectName = $this->argument('projectName');
		$domain = $projectName . '.localhost';
		// $projectType = $this->option('type');

		// Calculate the path of the new project
		$projectDirectory = getcwd() . '/' . $projectName;

		info("Preparing to delete $projectName at $projectDirectory");

		if (!is_dir($projectDirectory)) {
			error("Project '$projectName' does not exist.");
			return 1;
		}

		if (!confirm("Are you sure you want to delete project '$projectName' at '$projectDirectory'?")) {
			warning('Project not deleted.');
			return 1;
		}

		if ($projectName !== text("If you're absolutely sure you want to delete this project, type '$projectName' below.", placeholder: $projectName)) {
			warning('Project not deleted.');
			return 1;
		}

		alert("Deleting project '$projectName' at '$projectDirectory'");
		$site = Site::create($domain, $projectDirectory);

		// Delete the NGINX site for this project
		// $this->call('nginx:delete', ['domain' => $projectName]);
		$site->disable();
		$site->delete();
		Nginx::make()->restart();

		Certificate::destroy($domain);

		// and then tear down the database and user
		// $this->call('postgres:delete', ['database' => $projectName]);
		$postgres = Postgres::make();
		$dbName = $projectName;
		// Convert hyphens to underscores
		$dbName = str_replace('-', '_', $dbName);
		// Convert the db name to a valid database name, (only lowercase letters and underscores)
		$dbName = preg_replace('/[^a-z0-9_]/', '', $dbName);

		if (!$postgres->dropAll($dbName)) {
			alert('Failed to drop some database resources.');
		} else {
			info('Database resources dropped successfully!');
		}

		$this->title("$projectName deleted successfully!");
		info('If you wish, you may now manually delete the project\'s directory.');
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
