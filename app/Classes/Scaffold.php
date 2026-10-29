<?php

namespace App\Classes;

use Illuminate\Support\Facades\File;

/**
 * Moves a freshly created app (e.g. from `composer create-project` into a staging directory) into a
 * directory that already has files, like a README and some docs, without overwriting any of them.
 */
class Scaffold
{
	/** Files most projects start with: the existing copy is kept (and .gitignore gains the app's missing lines) */
	public const KEEP_EXISTING = ['README.md', 'AGENTS.md', 'CLAUDE.md', '.gitignore', '.gitattributes', '.editorconfig'];

	/**
	 * The top-level names in $directory (including dotfiles).
	 *
	 * @return string[]
	 */
	public static function entries(string $directory): array
	{
		if (!is_dir($directory)) {
			return [];
		}

		return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
	}

	/**
	 * Names both directories have that can't be combined, so moving $from into $into would overwrite something.
	 *
	 * @return string[]
	 */
	public static function conflicts(string $from, string $into): array
	{
		return array_values(array_diff(
			array_intersect(static::entries($from), static::entries($into)),
			static::KEEP_EXISTING
		));
	}

	/**
	 * Move everything in $from into $into, keeping what $into already has, then remove $from.
	 * Callers check conflicts() first: anything that clashes is left out rather than overwritten.
	 */
	public static function merge(string $from, string $into): bool
	{
		foreach (static::entries($from) as $name) {
			$source = "$from/$name";
			$target = "$into/$name";

			if (file_exists($target) || is_link($target)) {
				if ($name === '.gitignore') {
					static::mergeGitignore($source, $target);
				}
				continue;
			}

			// Same parent directory, so this is a rename on one filesystem, not a copy
			if (!@rename($source, $target)) {
				return false;
			}
		}

		return File::deleteDirectory($from);
	}

	/**
	 * Append the app's ignore rules that the existing .gitignore doesn't have yet.
	 */
	protected static function mergeGitignore(string $source, string $target): void
	{
		$existing = array_map('trim', file($target, FILE_IGNORE_NEW_LINES) ?: []);
		$missing = array_values(array_filter(
			array_map('trim', file($source, FILE_IGNORE_NEW_LINES) ?: []),
			fn ($line) => $line !== '' && !in_array($line, $existing, true)
		));

		if (!$missing) {
			return;
		}

		$contents = (string) file_get_contents($target);
		$separator = $contents === '' || str_ends_with($contents, "\n") ? '' : "\n";
		file_put_contents($target, $contents . $separator . "\n# Added with the Laravel app\n" . implode("\n", $missing) . "\n");
	}
}
