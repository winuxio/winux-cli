<?php

namespace App\Commands\Site;

use App\Classes\ServiceUser;
use App\Classes\Site;
use App\Commands\Concerns\ResolvesSite;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\{error, info, note, warning};

class FixSitePermissions extends Command
{
	use ResolvesSite;

	protected $signature = 'site:permissions
		{name? : Same as --site}
		{--site= : Site name or domain (defaults to the project in the current directory)}
		{--all : Fix every site}
		{--dry-run : Only report what would change}';

	protected $description = "Give a site's files back to you (e.g. ones www-data wrote) and make storage/ and bootstrap/cache writable";

	/** Laravel writes here at runtime */
	protected const WRITABLE = ['storage', 'bootstrap/cache'];

	public function handle(): int
	{
		$user = ServiceUser::resolve();
		if ($user === null) {
			return self::FAILURE;
		}

		if ($this->option('all')) {
			$sites = Site::all();
			if (empty($sites)) {
				error('No sites exist yet.');
				return self::FAILURE;
			}
		} else {
			$sites = array_filter([$this->resolveSite($this->option('site') ?? $this->argument('name'))]);
			if (empty($sites)) {
				return self::FAILURE;
			}
		}

		$failed = false;
		foreach ($sites as $site) {
			if (!$this->fix($site, $user)) {
				$failed = true;
			}
		}

		// Files will just end up owned by www-data again until the services run as the user
		if (!$this->option('dry-run') && ($misconfigured = ServiceUser::misconfigured())) {
			warning('nginx/PHP-FPM still run as another user for: ' . implode(', ', $misconfigured) . '. Run `winux service:configure` so new files stay yours.');
		}

		return $failed ? self::FAILURE : self::SUCCESS;
	}

	/**
	 * @param array{name: string, group: string, uid: int} $user
	 */
	protected function fix(Site $site, array $user): bool
	{
		$path = $site->projectPath();
		if (!$path || !is_dir($path)) {
			warning("Skipping {$site->domain}: its project directory " . ($path ?: '(unknown)') . " doesn't exist.");
			return true;
		}

		$path = rtrim(realpath($path) ?: $path, '/');
		// Never sweep a whole home directory (or worse) because a site's root was set oddly
		if ($path === '' || $path === '/' || $path === rtrim((string) config('winux.paths.home'), '/') || substr_count($path, '/') < 2) {
			error("Refusing to change ownership under '$path' for {$site->domain}; that's not a project directory.");
			return false;
		}

		// sudo, because files owned by someone else may sit in directories you can't read
		$foreign = Process::run(['sudo', 'find', $path, '!', '-user', $user['name'], '-print']);
		if ($foreign->failed()) {
			error("Couldn't scan $path: " . trim($foreign->errorOutput()));
			return false;
		}

		$files = array_values(array_filter(explode("\n", $foreign->output())));
		$writable = array_values(array_filter(
			array_map(fn ($dir) => "$path/$dir", static::WRITABLE),
			fn ($dir) => is_dir($dir)
		));

		if ($this->option('dry-run')) {
			info(count($files) ? count($files) . " file(s) in $path aren't owned by {$user['name']}:" : "Everything in $path is owned by {$user['name']}.");
			foreach (array_slice($files, 0, 10) as $file) {
				$this->line('  - ' . substr($file, strlen($path) + 1));
			}
			if (count($files) > 10) {
				$this->line('  - …and ' . (count($files) - 10) . ' more');
			}
			return true;
		}

		if ($files) {
			// -h changes symlinks themselves, never what they point at (which may be outside the project)
			$chown = Process::forever()->run(['sudo', 'find', $path, '!', '-user', $user['name'], '-exec', 'chown', '-h', "{$user['name']}:{$user['group']}", '{}', '+']);
			if ($chown->failed()) {
				error("Couldn't change ownership in $path: " . trim($chown->errorOutput()));
				return false;
			}
		}

		if ($writable && Process::run(['chmod', '-R', 'u+rwX', ...$writable])->failed()) {
			error('Couldn\'t make ' . implode(' and ', $writable) . ' writable.');
			return false;
		}

		info($files
			? 'Gave ' . count($files) . " file(s) in $path back to {$user['name']}."
			: "Everything in $path was already owned by {$user['name']}.");
		if ($writable) {
			note('Made ' . implode(' and ', array_map(fn ($dir) => substr($dir, strlen($path) + 1), $writable)) . ' writable.');
		}

		return true;
	}
}
