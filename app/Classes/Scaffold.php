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
	 * Remember what moving $from into $into adds, so deleting the project can take the app back out
	 * and leave your own files. Recorded before moving, so a move that stops halfway is covered too.
	 */
	public static function record(string $from, string $into): void
	{
		$existing = static::entries($into);

		File::ensureDirectoryExists(dirname(static::recordPath($into)));
		File::put(static::recordPath($into), json_encode([
			'path' => $into,
			'existing' => $existing,
			// .env comes with the app too, even when it's written after the move
			'added' => array_values(array_diff(array_unique([...static::entries($from), '.env']), $existing)),
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * What record() saved for $directory, or null when winux didn't add an app around existing files.
	 *
	 * @return array{path: string, existing: string[], added: string[]}|null
	 */
	public static function recorded(string $directory): ?array
	{
		$path = static::recordPath($directory);

		return is_file($path) ? (json_decode(File::get($path), true) ?: null) : null;
	}

	/**
	 * Delete what the app added to $directory and its rules in .gitignore, keeping everything that was
	 * there before. Returns what's left that wasn't there before (added after the app was created).
	 *
	 * @return string[]|null null when something couldn't be deleted
	 */
	public static function remove(string $directory): ?array
	{
		$record = static::recorded($directory);
		if (!$record) {
			return null;
		}

		foreach ($record['added'] as $name) {
			$target = "$directory/$name";
			// basename() guards against a tampered record reaching outside the directory
			if ($name !== basename($name) || in_array($name, ['.', '..', '.git'], true)) {
				continue;
			}
			$deleted = match (true) {
				is_dir($target) && !is_link($target) => File::deleteDirectory($target),
				file_exists($target) || is_link($target) => @unlink($target),
				default => true,
			};
			if (!$deleted) {
				return null;
			}
		}

		static::unmergeGitignore("$directory/.gitignore");
		@unlink(static::recordPath($directory));

		return array_values(array_diff(static::entries($directory), $record['existing']));
	}

	public static function forget(string $directory): void
	{
		@unlink(static::recordPath($directory));
	}

	protected static function recordPath(string $directory): string
	{
		return config('winux.paths.base') . '/scaffolds/' . sha1(rtrim($directory, '/')) . '.json';
	}

	/** Take back out the section mergeGitignore() appended */
	protected static function unmergeGitignore(string $path): void
	{
		if (!is_file($path)) {
			return;
		}

		$contents = (string) file_get_contents($path);
		$at = strpos($contents, "\n# Added with the Laravel app\n");
		if ($at !== false) {
			file_put_contents($path, substr($contents, 0, $at));
		}
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
