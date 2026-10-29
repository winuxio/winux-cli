<p align="center">
	<img title="Winux CLI" height="200" src="https://github.com/winuxio/winux-cli/assets/77644584/60f6b48a-1fe5-4350-8b9e-0bdc2ceed64a" />
</p>

<p align="center">
    <a href="https://github.com/winuxio/winux-cli/actions/workflows/laravel-zero-build.yml"><img src="https://github.com/winuxio/winux-cli/actions/workflows/laravel-zero-build.yml/badge.svg" alt="Build Status"></a>
    <img alt="Latest Release" src="https://img.shields.io/github/v/release/winuxio/winux-cli">
	<a href="https://discord.gg/ZWJQ22dX3b"><img alt="Discord" src="https://img.shields.io/discord/1137287672944144385"></a>
</p>

<h4><center><bold>Simplify</bold> local dev ops for Windows/WSL</center></h4>

Winux sets up nginx, PHP-FPM (any version, side by side), Postgres, Redis, and trusted HTTPS for your projects on WSL2, getting you ready to code in 30 seconds. Native Ubuntu and Debian work too.

## Requirements

- **WSL 2** running **Ubuntu** (22.04+) or **Debian** (12+), or a native Ubuntu/Debian machine
- **systemd** enabled. On WSL, add this to `/etc/wsl.conf`, then run `wsl --shutdown` from Windows:
  ```ini
  [boot]
  systemd=true
  ```

## Install

```bash
curl -fsSL https://raw.githubusercontent.com/winuxio/winux-cli/main/install.sh | bash
winux setup
```

`install.sh` adds the PHP repository ([ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php) on Ubuntu, [sury](https://packages.sury.org/php/) on Debian), installs a PHP CLI and Composer, and puts the `winux` binary in `/usr/local/bin` after checking its checksum. Set `WINUX_PHP=8.3` (winux itself needs PHP 8.3 or newer; sites can use older versions) or `WINUX_VERSION=v0.2.0` to override the defaults.

`winux setup` then asks which PHP versions and which Postgres version you want, and installs:

- the [PGDG](https://www.postgresql.org/download/linux/ubuntu/) Postgres repository and your chosen Postgres version (match production)
- nginx, Redis, and the chosen PHP-FPM versions with common extensions
- [mkcert](https://github.com/FiloSottile/mkcert), with its root CA trusted by Linux and by Windows (Chrome/Edge/Firefox on Windows)

Run `winux doctor` at any time to check that everything is healthy.

## Updating

winux checks for a new release once a day, in the background, and mentions it after your next command:

```
  winux 1.2.0 is available (you have 1.1.0). Run winux self-update to update.
```

```bash
winux self-update                 # update to the latest release, showing what's new
winux self-update --check         # just show what an update would bring
winux self-update 1.1.0           # install a specific version (older ones too)
winux self-update --rollback      # go back to the version you had before the last update
winux self-update --channel=beta  # opt into prereleases (--channel=stable to go back)
```

Run `winux self-update` as yourself, not with `sudo`: winux asks for your password itself when the binary's directory needs it, and running it as root would keep the rollback copy and your update channel in `/root/.winux` instead of `~/.winux`.

Every download, by `self-update` and by `install.sh`, is checked against the release's SHA-256 checksum, and is refused if the checksum is missing or can't be fetched. The only exception is pinning a release older than 0.2.0, which predates checksums and installs with a warning. A download must also run and report the expected version before it replaces the installed binary, and if replacing it fails, the installed binary and the rollback copy are left as they were. Releases also carry [build provenance](https://docs.github.com/en/actions/security-for-github-actions/using-artifact-attestations), so you can confirm a binary was built by this repository's CI:

```bash
gh attestation verify "$(command -v winux)" --repo winuxio/winux-cli
```

To turn off the daily check, run with `WINUX_NO_UPDATE_CHECK=1` or set `"update": {"check": false}` in `~/.winux/config.json`. It's also skipped in CI and when output isn't a terminal.

## Projects

```bash
winux project:create my-app                       # new Laravel app, served at https://my-app.local.winux.io
winux project:create my-app --php=8.3 --pg=16     # pick versions per project
winux project:create my-app --domain=my-app.test  # also answer to extra domains
winux project:import my-app                       # wire up an existing checkout in ./my-app
winux project:delete my-app
```

Creating or importing a project:

- generates an nginx site and a certificate
- creates a Postgres database and user
- updates `.env`
- optionally runs migrations, creates a GitHub repo, installs Filament, and opens your editor and browser

Import is safe to run on a project that's already set up, or one that's half set up:

- **Database:** if the credentials in `.env` already work, they're used as they are and the database isn't touched. If the database named in `.env` exists but the login fails, winux keeps that database. It then creates the user, or offers to reset the user's password. Only when nothing exists does it create a database and user named after the project.
- **`.env`:** import always updates `APP_URL` and any `DB_*` values it changed. Other settings (`APP_NAME`, cache, session, queue) are only filled in when they're missing.
- **nginx:** an existing site config is kept. Its PHP version only changes if you pass `--php`.

`*.local.winux.io` resolves to `127.0.0.1` through public DNS, so no hosts file edits are needed.

## Sites

The `site:*` commands work on any site, including ones created by older versions of winux or edited by hand. Only the lines winux owns (`server_name` and the php-fpm socket) are changed. Each edit is backed up to `~/.winux/backups/`, validated with `nginx -t`, and rolled back if validation fails. Run inside a project directory, they act on that project's site; otherwise pass `--site=<name>`.

```bash
winux site:list                                   # sites, PHP versions, extra domains, paths
winux site:php 8.3                                # switch the current project's site to PHP 8.3
winux site:php 8.5 --site=my-app                  # ...or name the site

winux site:domain:add api.my-app.local.winux.io   # extra names; the certificate is reissued to cover them
winux site:domain:add my-app.test                 # non-winux.io names get hosts entries (Windows + WSL)
winux site:domain:remove my-app.test

winux site:secure --all                           # reissue every site's certificate
```

## PHP & Postgres versions

```bash
winux php:list                    # installed / available versions, FPM status, sites per version
winux php:install 8.3 8.5
winux php:default 8.4             # version new sites use
winux php:default 8.4 --cli       # ...and switch the `php` CLI too

winux postgres:list               # installed versions and their ports
winux postgres:default 16         # version new projects use
winux service:install postgres 17 # add another version alongside
```

## Services

```bash
winux service:status
winux service:install [nginx|php|postgres|redis|mysql] [version]
winux service:start|stop|restart [service] [version]
winux nginx:restart | php:restart [version] | postgres:restart [version] | redis:restart
```

Services are managed with `systemctl`. If systemd isn't running, winux falls back to `service`.

## SSL

Certificates are issued by mkcert using the CA in `~/.winux/ssl/root`. If you used an older version of winux, its existing `winux_root` CA is adopted automatically, so browsers that already trust it keep working.

```bash
winux ssl:root:install            # (re)trust the root CA in Linux and Windows
```

## Files

```
~/.winux/
├── config.json       # php.default, postgres.default, update.channel, update.check
├── update-check.json # result of the last daily update check
├── winux.previous    # the binary before the last self-update (for --rollback)
├── sites/
│   ├── available/    # nginx site configs, named after the primary domain
│   └── enabled/      # symlinks to enabled sites (included from /etc/nginx/nginx.conf)
├── ssl/
│   ├── root/         # mkcert CAROOT (rootCA.pem, rootCA-key.pem)
│   └── certs/        # per-site certificates
└── backups/          # configs saved before winux edits them
```

## Upgrading from 0.1

- Your sites, certificates, and root CA are all kept. Run `winux doctor` to check everything.
- 0.1 can't update itself: install the latest release once with `install.sh` (above), then use `winux self-update` from then on.
- Older versions installed to `/usr/sbin/winux`. Remove that copy with `sudo rm /usr/sbin/winux`.
- The default site template now redirects HTTP to `https://$host`, so extra domains redirect to themselves. Older sites are updated the first time you add a domain to them.

## Development

```bash
composer install
php winux                  # run from source
composer test              # Pest
php winux app:build        # build the PHAR into builds/winux
php winux app:install:local
```

### Releasing

Releases are automated with [release-please](https://github.com/googleapis/release-please):

1. PRs are squash-merged, and their titles must be [conventional commits](https://www.conventionalcommits.org/) (checked by CI): `feat: ...` for features, `fix: ...` for bug fixes, `feat!: ...` for breaking changes (a minor bump while winux is 0.x). `docs:`, `chore:`, `refactor:`, `test:` and `ci:` don't trigger a release.
2. release-please keeps a `chore(main): release x.y.z` PR open with the version bump and `CHANGELOG.md`, updated as PRs land.
3. Merging that PR tags the release and creates it as a draft. CI then runs the tests, builds the PHAR, attaches `winux`, `winux.sha256` and a provenance attestation, and only then publishes the release, so the latest release always has a binary. Users get the update notice within a day.

If that publish run fails, fix the cause and re-run it. If more commits have landed on `main` since, run the **Release** workflow by hand (Actions → Release → Run workflow) with the release's tag, e.g. `v1.2.0`; it builds and publishes that tag, and also works for prerelease tags.

release-please opens its PR with the `RELEASE_PLEASE_TOKEN` repository secret: a [fine-grained token](https://github.com/settings/personal-access-tokens/new) whose resource owner is `winuxio`, limited to this repository, with **Contents** and **Pull requests** set to read and write. It falls back to the built-in token, which only works if the org allows Actions to create pull requests (and CI won't run on the release PR).

Prereleases (the beta channel) are cut by hand from any commit:

```bash
git tag v1.2.0-beta.1 && git push origin v1.2.0-beta.1
```

## Support the development

- GitHub: [Sponsor](https://github.com/sponsors/chrisreedio)

## Credits

- [Chris Reed](https://github.com/chrisreedio)
- [All Contributors](../../contributors)

## License

Winux CLI is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE).
