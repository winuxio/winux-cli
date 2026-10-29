<?php

namespace App\Classes;

/**
 * Reads and surgically edits a winux site's nginx config. Only the directives winux owns
 * (server_name, the php-fpm socket) are touched so hand edits survive.
 */
class NginxConfig
{
	public function __construct(public string $contents)
	{
	}

	/**
	 * Every domain listed in any server_name directive, in order of appearance.
	 *
	 * @return string[]
	 */
	public function domains(): array
	{
		preg_match_all('/^\s*server_name\s+([^;]+);/m', $this->contents, $matches);

		return collect($matches[1])
			->flatMap(fn ($names) => preg_split('/\s+/', trim($names)))
			->filter()
			->unique()
			->values()
			->all();
	}

	public function phpVersion(): ?string
	{
		return preg_match('/php(\d+\.\d+)-fpm\.sock/', $this->contents, $matches) ? $matches[1] : null;
	}

	public function root(): ?string
	{
		return preg_match('/^\s*root\s+([^;]+);/m', $this->contents, $matches) ? trim($matches[1]) : null;
	}

	public function withDomains(array $domains): static
	{
		$names = implode(' ', $domains);

		$contents = preg_replace('/^(\s*server_name\s+)[^;]+;/m', '${1}' . $names . ';', $this->contents);

		// $server_name is always the first name; redirect to whatever host was requested instead
		$contents = str_replace('https://$server_name$request_uri', 'https://$host$request_uri', $contents);

		return new static($contents);
	}

	public function withPhpVersion(string $version): static
	{
		return new static(preg_replace('/php\d+\.\d+-fpm\.sock/', "php{$version}-fpm.sock", $this->contents));
	}
}
