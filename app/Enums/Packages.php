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

		return $this->configure($path);
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
			// With npm it also adds and builds laravel-echo and pusher-js, which the generated echo.js imports
			self::Reverb => [['install:broadcasting', '--reverb', ...$withComposer, ...(Platform::commandExists('npm') ? [] : ['--without-node'])]],
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
	protected function configure(string $path): bool
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
				if (!Platform::commandExists('npm')) {
					warning('npm was not found, so the front end is missing laravel-echo and pusher-js: run `npm install --save-dev laravel-echo pusher-js` once Node is installed.');
				}
				break;

			case self::Sanctum:
				note('Add the Laravel\Sanctum\HasApiTokens trait to your User model to issue API tokens.');
				break;

			case self::Passport:
				note('Add the Laravel\Passport\HasApiTokens trait to your User model, and an `api` guard using the passport driver in config/auth.php.');
				break;

			case self::Socialment:
				if (!static::insertAfter("$path/app/Providers/Filament/AdminPanelProvider.php", '/^(\s*)->login\(\)\n/m', "            ->plugin(\\ChrisReedIO\\Socialment\\SocialmentPlugin::make())\n")) {
					warning('Add `->plugin(\ChrisReedIO\Socialment\SocialmentPlugin::make())` to your Filament panel.');
				}
				note("Socialment's styles need a custom Filament theme: see https://github.com/chrisreedio/socialment#2-add-styling-support. Then add your Socialite providers.");
				break;

			default:
				break;
		}

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
