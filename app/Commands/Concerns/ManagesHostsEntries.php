<?php

namespace App\Commands\Concerns;

use App\Classes\Hosts;

use function Laravel\Prompts\{confirm, note, warning};

trait ManagesHostsEntries
{
	/**
	 * Offers to add hosts entries for domains outside the *.local.winux.io wildcard.
	 */
	protected function offerHostsEntries(array $domains, bool $skip = false): void
	{
		$needsHosts = array_values(array_filter($domains, fn ($domain) => !Hosts::coveredByWildcard($domain)));
		if (empty($needsHosts)) {
			return;
		}

		$wildcards = array_filter($needsHosts, fn ($domain) => str_starts_with($domain, '*.'));
		$plain = array_values(array_diff($needsHosts, $wildcards));

		if ($wildcards) {
			warning('Hosts files cannot contain wildcards. Point DNS for ' . implode(', ', $wildcards) . ' at 127.0.0.1 yourself.');
		}

		if (empty($plain)) {
			return;
		}

		$hint = implode(', ', $plain) . ' must resolve to 127.0.0.1.';
		if ($skip || !$this->input->isInteractive() || !confirm('Add hosts file entries for ' . implode(', ', $plain) . '?', true, hint: $hint)) {
			note("Skipped hosts entries. $hint");
			return;
		}

		Hosts::add($plain);
	}
}
