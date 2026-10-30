<?php

namespace App\Enums;

use App\Classes\Platform;
use App\Classes\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Packages project:create offers, in the order they install (Filament before anything that plugs into it).
 */
enum Packages: string
{
	case Filament = 'filament';
	case Boost = 'boost';
	case Pao = 'pao';
	case Larastan = 'larastan';
	case Telescope = 'telescope';
	case Horizon = 'horizon';
	case Pulse = 'pulse';
	case Sentry = 'sentry';
	case Reverb = 'reverb';
	case Sanctum = 'sanctum';
	case Passport = 'passport';
	case Saloon = 'saloon';
	case Socialment = 'socialment';
	case FontAwesome = 'fontawesome';
	case S3 = 's3';
	case ActivityLog = 'activitylog';

	public function label(): string
	{
		return match ($this) {
			self::Filament => 'Filament',
			self::Boost => 'Laravel Boost',
			self::Pao => 'Laravel Pao',
			self::Larastan => 'Larastan',
			self::Telescope => 'Telescope',
			self::Horizon => 'Horizon',
			self::Pulse => 'Pulse',
			self::Sentry => 'Sentry',
			self::Reverb => 'Reverb',
			self::Sanctum => 'Sanctum',
			self::Passport => 'Passport',
			self::Saloon => 'Saloon',
			self::Socialment => 'Socialment',
			self::FontAwesome => 'Blade Font Awesome',
			self::S3 => 'S3 storage driver',
			self::ActivityLog => 'Activity log',
		};
	}

	/** What it is, shown next to the label when choosing */
	public function description(): string
	{
		return match ($this) {
			self::Filament => 'admin panel',
			self::Boost => 'AI guidelines, skills and MCP server',
			self::Pao => 'agent-friendly test output',
			self::Larastan => 'static analysis',
			self::Telescope => 'debug dashboard',
			self::Horizon => 'Redis queue dashboard',
			self::Pulse => 'performance dashboard',
			self::Sentry => 'error tracking',
			self::Reverb => 'WebSockets and broadcasting',
			self::Sanctum => 'API routes and tokens',
			self::Passport => 'OAuth2 server',
			self::Saloon => 'API integrations',
			self::Socialment => 'social login for Filament',
			self::FontAwesome => 'Font Awesome icons in Blade',
			self::S3 => 'league/flysystem-aws-s3-v3',
			self::ActivityLog => 'spatie/laravel-activitylog',
		};
	}

	/**
	 * The Composer packages to require; null when an artisan installer requires them itself.
	 *
	 * @return string[]|null
	 */
	public function composerPackages(): ?array
	{
		return match ($this) {
			self::Filament => ['filament/filament'],
			self::Boost => ['laravel/boost'],
			self::Pao => ['laravel/pao'],
			self::Larastan => ['larastan/larastan'],
			self::Telescope => ['laravel/telescope'],
			self::Horizon => ['laravel/horizon'],
			self::Pulse => ['laravel/pulse'],
			self::Sentry => ['sentry/sentry-laravel'],
			self::Saloon => ['saloonphp/saloon', 'saloonphp/laravel-plugin'],
			self::Socialment => ['chrisreedio/socialment'],
			self::FontAwesome => ['owenvoke/blade-fontawesome'],
			self::S3 => ['league/flysystem-aws-s3-v3'],
			self::ActivityLog => ['spatie/laravel-activitylog'],
			// install:api and install:broadcasting require their packages themselves
			self::Reverb, self::Sanctum, self::Passport => null,
		};
	}

	public function isDev(): bool
	{
		return in_array($this, [self::Boost, self::Pao, self::Larastan], true);
	}

	/**
	 * Packages this one plugs into, installed first.
	 *
	 * @return Packages[]
	 */
	public function requires(): array
	{
		return match ($this) {
			self::Socialment => [self::Filament],
			default => [],
		};
	}

	/**
	 * Packages that can't be installed alongside this one.
	 *
	 * @return Packages[]
	 */
	public function conflicts(): array
	{
		return match ($this) {
			// Both put a HasApiTokens trait on the User model
			self::Sanctum => [self::Passport],
			self::Passport => [self::Sanctum],
			default => [],
		};
	}

	/**
	 * Why $packages can't be installed together, or null when they can.
	 *
	 * @param Packages[] $packages
	 */
	public static function conflictError(array $packages): ?string
	{
		foreach ($packages as $package) {
			foreach ($package->conflicts() as $other) {
				if (in_array($other, $packages, true)) {
					return "Pick either {$package->label()} or {$other->label()}: both add a HasApiTokens trait to the User model.";
				}
			}
		}

		return null;
	}

	/** Whether installing it adds migrations to run */
	public function addsMigrations(): bool
	{
		return in_array($this, [self::Telescope, self::Pulse, self::Sanctum, self::Passport, self::Socialment, self::ActivityLog], true);
	}

	/**
	 * The chosen packages plus what they require, in install order.
	 *
	 * @param Packages[] $packages
	 * @return Packages[]
	 */
	public static function resolve(array $packages): array
	{
		foreach ($packages as $package) {
			foreach ($package->requires() as $required) {
				if (!in_array($required, $packages, true)) {
					note("{$package->label()} needs {$required->label()}, so it's installed too.");
					$packages[] = $required;
				}
			}
		}

		return array_values(array_filter(self::cases(), fn ($case) => in_array($case, $packages, true)));
	}

	public function install(string $path, string $php = 'php'): bool
	{
		$guzzle = static::lockedVersion($path, 'guzzlehttp/guzzle');
		if (!$this->runInstallers($path, $php)) {
			return false;
		}

		// Reverb needs pusher/pusher-php-server, which only works with Guzzle 7
		$now = static::lockedVersion($path, 'guzzlehttp/guzzle');
		if ($guzzle && $now && (int) ltrim($now, 'v') < (int) ltrim($guzzle, 'v')) {
			warning("Composer moved guzzlehttp/guzzle from $guzzle to $now so {$this->label()} fits.");
		}

		return $this->configure($path, $php);
	}

	protected function runInstallers(string $path, string $php): bool
	{
		$composerPath = Project::composerPath();
		$composer = $composerPath ?? 'composer';

		// No constraints: composer picks the newest versions compatible with the project
		if ($packages = $this->composerPackages()) {
			$command = [$php, $composer, 'require', ...$packages, '-W', ...($this->isDev() ? ['--dev'] : [])];
			$process = Process::path($path)->forever()->run($command);
			if ($process->failed()) {
				error("Failed to require {$this->label()} via Composer.");
				warning($process->errorOutput());
				return false;
			}
		}

		foreach ($this->artisanCommands($composerPath) as $arguments) {
			$process = Process::path($path)->forever()->run([$php, 'artisan', ...$arguments, '--no-interaction']);
			if ($process->failed()) {
				error("Failed to install {$this->label()}: `php artisan " . implode(' ', $arguments) . '` failed.');
				warning($process->errorOutput() ?: $process->output());
				return false;
			}
		}

		return true;
	}

	/** The version of $package in the project's composer.lock, if it's there */
	protected static function lockedVersion(string $path, string $package): ?string
	{
		$lock = is_file("$path/composer.lock") ? json_decode((string) file_get_contents("$path/composer.lock"), true) : null;

		foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $locked) {
			if (($locked['name'] ?? null) === $package) {
				return $locked['version'] ?? null;
			}
		}

		return null;
	}

	/**
	 * Installer commands to run after requiring the package.
	 *
	 * @return array<int, string[]>
	 */
	protected function artisanCommands(?string $composer): array
	{
		// Laravel's installers take an absolute path to Composer, and otherwise look for a global one
		$withComposer = $composer ? ["--composer=$composer"] : [];

		return match ($this) {
			self::Filament => [['filament:install', '--panels']],
			self::Boost => [['boost:install', '--guidelines', '--skills', '--mcp']],
			self::Telescope => [['telescope:install']],
			self::Horizon => [['horizon:install']],
			self::Pulse => [['vendor:publish', '--tag=pulse-config'], ['vendor:publish', '--tag=pulse-migrations']],
			// Its own npm step needs a terminal (it fails without one), so configure() runs npm instead
			self::Reverb => [['install:broadcasting', '--reverb', ...$withComposer, '--without-node']],
			self::Sanctum => [['install:api', ...$withComposer, '--without-migration-prompt']],
			self::Passport => [['install:api', '--passport', ...$withComposer, '--without-migration-prompt']],
			self::Socialment => [['socialment:install']],
			self::ActivityLog => [['vendor:publish', '--provider=Spatie\Activitylog\ActivitylogServiceProvider', '--tag=activitylog-migrations']],
			default => [],
		};
	}

	/**
	 * Wiring the installers leave to you.
	 */
	protected function configure(string $path, string $php = 'php'): bool
	{
		switch ($this) {
			case self::Larastan:
				if (!is_file("$path/phpstan.neon") && !is_file("$path/phpstan.neon.dist")) {
					File::put("$path/phpstan.neon", <<<'NEON'
					includes:
					    - vendor/larastan/larastan/extension.neon

					parameters:
					    level: 5
					    paths:
					        - app
					        - config
					        - database
					        - routes
					    excludePaths:
					        # Mostly published by packages (Pulse's don't pass level 5)
					        - database/migrations
					    tmpDir: storage/framework/cache/phpstan

					NEON);
				}
				note('Run Larastan with `vendor/bin/phpstan analyse`.');
				break;

			case self::Sentry:
				// Laravel 11+ reports exceptions through bootstrap/app.php
				if (!static::insertAfter("$path/bootstrap/app.php", '/->withExceptions\(function \(Exceptions \$exceptions\)(?:: void)? \{\n/', "        \\Sentry\\Laravel\\Integration::handles(\$exceptions);\n")) {
					warning('Add `\Sentry\Laravel\Integration::handles($exceptions);` to withExceptions() in bootstrap/app.php.');
				}
				note('Set SENTRY_LARAVEL_DSN in .env to start sending errors to Sentry.');
				break;

			case self::Reverb:
				// The generated resources/js/echo.js imports these
				static::npm($path, ['install', '--save-dev', 'laravel-echo', 'pusher-js'], 'npm install --save-dev laravel-echo pusher-js');
				break;

			case self::Sanctum:
				if (!static::addTrait("$path/app/Models/User.php", 'Laravel\Sanctum\HasApiTokens')) {
					warning('Add the Laravel\Sanctum\HasApiTokens trait to your User model to issue API tokens.');
				}
				break;

			case self::Passport:
				$user = "$path/app/Models/User.php";
				if (!static::addTrait($user, 'Laravel\Passport\HasApiTokens') || !static::addInterface($user, 'Laravel\Passport\Contracts\OAuthenticatable')) {
					warning('Add the Laravel\Passport\HasApiTokens trait to your User model, and have it implement Laravel\Passport\Contracts\OAuthenticatable.');
				}
				if (!static::insertAfter("$path/config/auth.php", "/'guards' => \[\n(\s*)'web' => \[\n(?:.*\n)*?\s*\],\n/", "        'api' => [\n            'driver' => 'passport',\n            'provider' => 'users',\n        ],\n")) {
					warning('Add an `api` guard using the passport driver to config/auth.php.');
				}
				break;

			case self::Socialment:
				if (!static::insertAfter("$path/app/Providers/Filament/AdminPanelProvider.php", '/^(\s*)->login\(\)\n/m', "            ->plugin(\\ChrisReedIO\\Socialment\\SocialmentPlugin::make())\n")) {
					warning('Add `->plugin(\ChrisReedIO\Socialment\SocialmentPlugin::make())` to your Filament panel.');
				}
				if (!static::socialmentTheme($path, $php)) {
					warning("Socialment's styles need a custom Filament theme with `@source '../../../../vendor/chrisreedio/socialment/resources';`: see https://github.com/chrisreedio/socialment#2-add-styling-support.");
				}
				note('Then add your Socialite providers: see https://github.com/chrisreedio/socialment#provider-configuration.');
				break;

			default:
				break;
		}

		return true;
	}

	/**
	 * Give the admin panel a custom theme (unless it has one) that includes Socialment's views, and build it.
	 */
	protected static function socialmentTheme(string $path, string $php): bool
	{
		$theme = "$path/resources/css/filament/admin/theme.css";
		if (!is_file($theme)) {
			if (!Platform::commandExists('npm')) {
				return false;
			}
			$process = Process::path($path)->forever()->run([$php, 'artisan', 'make:filament-theme', 'admin', '--pm=npm', '--no-interaction']);
			if ($process->failed() || !is_file($theme)) {
				return false;
			}
		}

		$source = "@source '../../../../vendor/chrisreedio/socialment/resources';";
		$contents = File::get($theme);
		if (!str_contains($contents, 'chrisreedio/socialment')) {
			File::put($theme, rtrim($contents) . "\n$source\n");
		}

		// make:filament-theme adds the theme to Vite's inputs, but not to the panel
		$vite = "$path/vite.config.js";
		$registered = static::insertAfter("$path/app/Providers/Filament/AdminPanelProvider.php", '/^(\s*)->login\(\)\n/m', "            ->viteTheme('resources/css/filament/admin/theme.css')\n")
			&& (is_file($vite) && str_contains(File::get($vite), 'resources/css/filament/admin/theme.css')
				|| static::insertAfter($vite, "/input: \[/", "'resources/css/filament/admin/theme.css', "));

		return $registered && static::npm($path, ['run', 'build'], 'npm run build');
	}

	/**
	 * Run npm in the project, or say what to run once Node is installed.
	 *
	 * @param string[] $arguments
	 */
	protected static function npm(string $path, array $arguments, string $command): bool
	{
		if (!Platform::commandExists('npm')) {
			warning("npm was not found: run `$command` once Node is installed.");
			return false;
		}

		$process = Process::path($path)->forever()->run(['npm', ...$arguments]);
		if ($process->failed()) {
			warning("`$command` failed: " . trim($process->errorOutput() ?: $process->output()));
			return false;
		}

		return true;
	}

	/**
	 * Import $trait into the class in $file and use it, alongside the traits it already uses.
	 */
	protected static function addTrait(string $file, string $trait): bool
	{
		$name = class_basename($trait);
		if (!is_file($file) || !static::addImport($file, $trait)) {
			return false;
		}

		$contents = File::get($file);
		if (preg_match("/^\s*use [^;\\\\]*\b$name\b[^;]*;/m", $contents)) {
			return true;
		}

		// The class's first `use A, B;` line
		$updated = preg_replace('/(\{\n(?:\s*\/\*\*.*?\*\/\n)?\s*use )([A-Za-z]+(?:, [A-Za-z]+)*;)/s', "\${1}$name, \${2}", $contents, 1, $count);
		if (!$count) {
			return false;
		}

		File::put($file, $updated);
		return true;
	}

	/**
	 * Import $interface into $file and add it to the class's implements list.
	 */
	protected static function addInterface(string $file, string $interface): bool
	{
		$name = class_basename($interface);
		if (!is_file($file) || !static::addImport($file, $interface)) {
			return false;
		}

		$contents = File::get($file);
		if (preg_match("/^class \w+[^{]*\bimplements\b[^{]*\b$name\b/m", $contents)) {
			return true;
		}

		$updated = preg_replace('/^(class \w+(?: extends [\w\\\\]+)?)( implements ([\w\\\\, ]+))?(\s*\n?\{)/m', '$1 implements ' . $name . '$2$4', $contents, 1, $count);
		$updated = str_replace(" implements $name implements ", " implements $name, ", (string) $updated);
		if (!$count) {
			return false;
		}

		File::put($file, $updated);
		return true;
	}

	/** Add `use $class;` after the file's last import */
	protected static function addImport(string $file, string $class): bool
	{
		$contents = File::get($file);
		if (str_contains($contents, "use $class;")) {
			return true;
		}

		if (!preg_match_all('/^use [^;]+;\n/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
			return false;
		}

		$last = end($matches[0]);
		$at = $last[1] + strlen($last[0]);
		File::put($file, substr($contents, 0, $at) . "use $class;\n" . substr($contents, $at));
		return true;
	}

	/**
	 * Insert $text after the first match of $pattern in $file; false when the file or the pattern isn't there.
	 */
	protected static function insertAfter(string $file, string $pattern, string $text): bool
	{
		if (!is_file($file)) {
			return false;
		}

		$contents = File::get($file);
		if (str_contains($contents, trim($text)) || !preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
			return str_contains($contents, trim($text));
		}

		$at = $match[0][1] + strlen($match[0][0]);
		File::put($file, substr($contents, 0, $at) . $text . substr($contents, $at));

		return true;
	}
}
