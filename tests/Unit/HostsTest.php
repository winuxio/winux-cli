<?php

use App\Classes\Hosts;

it('validates domain names', function (string $domain, bool $valid) {
	expect(Hosts::isValidDomain($domain))->toBe($valid);
})->with([
	['my-app.test', true],
	['api.my-app.local.winux.io', true],
	['*.my-app.test', true],
	['chris-pc.tail435585.ts.net', true],
	['localhost', false],
	['-bad.test', false],
	['bad_name.test', false],
	['spaces in.test', false],
	['foo.test;evil', false],
]);

it('knows which domains the public wildcard covers', function () {
	expect(Hosts::coveredByWildcard('api.my-app.local.winux.io'))->toBeTrue()
		->and(Hosts::coveredByWildcard('my-app.test'))->toBeFalse()
		->and(Hosts::coveredByWildcard('evil-local.winux.io'))->toBeFalse();
});

it('adds tagged entries without disturbing existing lines', function () {
	$original = "127.0.0.1\tlocalhost\n10.0.0.5\tnas.lan\n";

	expect(Hosts::rewrite($original, ['my-app.test'], []))
		->toBe("127.0.0.1\tlocalhost\n10.0.0.5\tnas.lan\n127.0.0.1\tmy-app.test # winux\n");
});

it('only removes entries winux added', function () {
	$original = "127.0.0.1\tmy-app.test\n127.0.0.1\tmy-app.test # winux\n127.0.0.1\tother.test # winux\n";

	expect(Hosts::rewrite($original, [], ['my-app.test']))
		->toBe("127.0.0.1\tmy-app.test\n127.0.0.1\tother.test # winux\n");
});

it('does not duplicate entries and keeps windows line endings', function () {
	$original = "# Windows hosts\r\n127.0.0.1\tmy-app.test # winux\r\n";

	expect(Hosts::rewrite($original, ['my-app.test'], []))
		->toBe("# Windows hosts\r\n127.0.0.1\tmy-app.test # winux\r\n");
});
