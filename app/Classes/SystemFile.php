<?php

namespace App\Classes;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\{alert, error, info};

/**
 * Edits root-owned config files (nginx.conf, PHP-FPM pools) via `sudo tee`: backed up to
 * ~/.winux/backups first, validated after writing, and restored if validation fails.
 */
class SystemFile
{
	/**
	 * @param callable(): (true|string) $validate Returns true, or the validation error
	 */
	public static function replace(string $path, string $contents, callable $validate): bool
	{
		$previous = @file_get_contents($path);
		if ($previous === false) {
			error("Unable to read $path.");
			return false;
		}

		if ($previous === $contents) {
			return true;
		}

		if (!($backup = static::backup($path, $previous))) {
			error("Failed to back up $path; leaving it unchanged.");
			return false;
		}

		if (!static::write($path, $contents)) {
			static::write($path, $previous);
			return false;
		}

		if (($result = $validate()) !== true) {
			if (static::write($path, $previous)) {
				alert("The updated $path failed validation; the previous version was restored.");
			} else {
				alert("The updated $path failed validation, and restoring it failed. Copy $backup back to $path.");
			}
			error($result);
			return false;
		}

		info("Updated $path (backup: $backup)");
		return true;
	}

	public static function write(string $path, string $contents): bool
	{
		$process = Process::input($contents)->run(['sudo', 'tee', $path]);
		if ($process->failed()) {
			error("Failed to write $path: " . $process->errorOutput());
			return false;
		}

		return true;
	}

	protected static function backup(string $path, string $contents): ?string
	{
		$dir = config('winux.paths.base') . DIRECTORY_SEPARATOR . 'backups';
		File::ensureDirectoryExists($dir);

		$stem = $dir . DIRECTORY_SEPARATOR . str_replace('/', '_', ltrim($path, '/')) . '.' . (new \DateTimeImmutable())->format('Ymd-His-u');
		$backup = $stem;
		for ($i = 1; file_exists($backup); $i++) {
			$backup = "$stem-$i";
		}

		return File::put($backup, $contents) === false ? null : $backup;
	}
}
