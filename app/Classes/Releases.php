<?php

namespace App\Classes;

use App\Http\Integrations\GitHub\GitHubConnector;
use App\Http\Integrations\GitHub\Requests\DownloadAssetRequest;
use App\Http\Integrations\GitHub\Requests\ListReleasesRequest;
use RuntimeException;

/**
 * winux releases published on GitHub, each with a `winux` PHAR and a `winux.sha256` checksum.
 */
class Releases
{
	public const CHANNELS = ['stable', 'beta'];

	/**
	 * Published releases that have a binary, newest first.
	 *
	 * @return array<int, array{version: string, tag: string, prerelease: bool, notes: string, url: string, binary: int, checksum: ?int}>
	 */
	public static function all(): array
	{
		$releases = (new GitHubConnector)
			->send(new ListReleasesRequest(static::repository()))
			->json();

		return static::parse($releases);
	}

	public static function parse(array $releases): array
	{
		$asset = config('winux.github.asset');

		return collect($releases)
			->reject(fn (array $release) => $release['draft'] ?? false)
			->map(function (array $release) use ($asset) {
				$assets = collect($release['assets'] ?? []);

				return [
					'version' => static::normalize($release['tag_name']),
					'tag' => $release['tag_name'],
					'prerelease' => (bool) ($release['prerelease'] ?? false),
					'notes' => (string) ($release['body'] ?? ''),
					'url' => (string) ($release['html_url'] ?? ''),
					'binary' => $assets->firstWhere('name', $asset)['id'] ?? null,
					'checksum' => $assets->firstWhere('name', "$asset.sha256")['id'] ?? null,
				];
			})
			// Releases are published a minute or two before CI attaches the binary
			->filter(fn (array $release) => $release['binary'] !== null)
			->sort(fn (array $a, array $b) => version_compare($b['version'], $a['version']))
			->values()
			->all();
	}

	public static function latest(array $releases, string $channel = 'stable'): ?array
	{
		return collect($releases)
			->first(fn (array $release) => $channel === 'beta' || !$release['prerelease']);
	}

	public static function find(array $releases, string $version): ?array
	{
		$version = static::normalize($version);

		return collect($releases)->firstWhere('version', $version);
	}

	/**
	 * Releases after $from up to and including $to, newest first: what an update brings.
	 */
	public static function between(array $releases, string $from, string $to, string $channel = 'stable'): array
	{
		return collect($releases)
			->filter(fn (array $release) => static::isNewer($release['version'], $from)
				&& version_compare($release['version'], $to, '<=')
				&& ($channel === 'beta' || !$release['prerelease'] || $release['version'] === $to))
			->values()
			->all();
	}

	/**
	 * Downloads a release's binary to a temp file and verifies its checksum.
	 * Returns the path, and whether a checksum was available to verify against.
	 *
	 * @return array{0: string, 1: bool}
	 */
	public static function download(array $release): array
	{
		$connector = new GitHubConnector;
		$binary = $connector->send(new DownloadAssetRequest(static::repository(), $release['binary']))->body();

		$verified = false;
		if ($release['checksum'] !== null) {
			$expected = static::parseChecksum($connector->send(new DownloadAssetRequest(static::repository(), $release['checksum']))->body());
			if (!hash_equals($expected, hash('sha256', $binary))) {
				throw new RuntimeException("Checksum mismatch for winux {$release['version']}; the download may be corrupt.");
			}
			$verified = true;
		}

		$path = tempnam(sys_get_temp_dir(), 'winux-');
		file_put_contents($path, $binary);
		chmod($path, 0755);

		return [$path, $verified];
	}

	/**
	 * Accepts `sha256sum` output ("<hash>  winux") or a bare hash.
	 */
	public static function parseChecksum(string $contents): string
	{
		$hash = strtolower(strtok(trim($contents), " \t\n") ?: '');
		if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
			throw new RuntimeException('The published checksum is malformed.');
		}

		return $hash;
	}

	/**
	 * The running version, or null when running from source (where the version is a git hash).
	 */
	public static function current(): ?string
	{
		$version = static::normalize((string) config('app.version'));

		return preg_match('/^\d+\.\d+\.\d+/', $version) ? $version : null;
	}

	public static function isNewer(string $version, ?string $than): bool
	{
		return $than !== null && version_compare(static::normalize($version), static::normalize($than), '>');
	}

	public static function normalize(string $version): string
	{
		return ltrim(trim($version), 'vV');
	}

	public static function channel(): string
	{
		$channel = Settings::get('update.channel', 'stable');

		return in_array($channel, static::CHANNELS, true) ? $channel : 'stable';
	}

	/**
	 * The installed PHAR, or null when running from source.
	 */
	public static function binary(): ?string
	{
		return config('winux.binary') ?: (\Phar::running(false) ?: null);
	}

	/**
	 * Where the binary is kept before an update, for `self-update --rollback`.
	 */
	public static function previousBinary(): string
	{
		return config('winux.paths.base') . DIRECTORY_SEPARATOR . 'winux.previous';
	}

	public static function repository(): string
	{
		return config('winux.github.repository');
	}
}
