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
- nginx, Redis, and the chosen PHP-FPM versions with common extensions, set up to [run as you](#nginx-and-php-fpm-run-as-you)
- [mkcert](https://github.com/FiloSottile/mkcert), with its root CA trusted by Linux and by Windows (Chrome/Edge/Firefox on Windows)

If Windows can't be reached (or you decline its prompt), setup still finishes and tells you how to trust the CA in Windows later. Use `winux setup --skip-windows` to only trust it inside Linux/WSL. `--php=8.4 --php=8.3` and `--pg=16` skip the questions.

Run `winux doctor` at any time to check that everything is healthy.

### nginx and PHP-FPM run as you

Like [Valet](https://laravel.com/docs/valet), winux runs nginx and PHP-FPM as your user instead of `www-data`. Ubuntu 24.04 creates home directories that other users can't enter (`0750`), so a `www-data` nginx can't read a project in your home (404) and a `www-data` PHP can't write to `storage/` (500). Running as you means no `chmod`/`chown` on your projects, and files the app writes (logs, cache, uploads) belong to you.

winux sets the `user` directive in `/etc/nginx/nginx.conf` and `user`, `group`, `listen.owner` and `listen.group` in each PHP version's `/etc/php/<version>/fpm/pool.d/www.conf` (nothing else in those files changes). Each file is backed up to `~/.winux/backups/` first, checked with `nginx -t` / `php-fpm<version> -t`, and restored if the check fails. This happens during `winux setup`, `winux php:install` (and `service:install php`), and `winux service:install nginx`.

On a machine set up by an older winux, `winux doctor` shows "nginx/PHP-FPM run as &lt;you&gt;" failing. Fix it (for nginx and every installed PHP version at once) with:

```bash
winux service:configure
```

It's safe to run again at any time. Projects may already contain files written by `www-data` (logs and cache in `storage/`, uploads, ...). Hand them back once:

```bash
winux site:permissions                # the project in the current directory (or --site=<name>)
winux site:permissions --all          # every site
winux site:permissions --dry-run      # just list the files that aren't yours
```

It changes the owner of every file in the project that isn't yours to you (symlinks themselves, never what they point to), and makes `storage/` and `bootstrap/cache` writable. Use it any time a site fails with "Permission denied" in `storage/logs`.

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
winux project:import                              # ...or the project you're in (same as `.`); paths like ../my-app work too
winux project:delete my-app                       # remove its site and certificate (asks about the database and files)
winux project:delete my-app --force --delete-database --delete-files  # no prompts, everything
```

**Project names** are the name of a directory in the current directory. They may contain letters, digits, hyphens and underscores, and must start and end with a letter or digit. The directory keeps the name exactly as you typed it, while the domain and database use a lowercase form with underscores turned into hyphens: `My_App` lives in `./My_App`, is served at `https://my-app.local.winux.io`, and gets the database `my_app`. If a site already serves that directory (for example `My_App.local.winux.io` from an older winux), it's kept. Paths with spaces (like `/mnt/c/Users/John Doe/code`) are fine, but nginx can't serve from a path containing `"`, `;`, `{`, `}`, `$` or `\`, so those are refused.

Creating or importing a project:

- generates an nginx site and a certificate, checks the result with `nginx -t`, and removes the new site again if nginx rejects it
- creates a Postgres database and user
- updates `.env`
- offers to run migrations, and to open your editor and browser

`project:create` also offers to create a git repository (and a GitHub repository with [`gh`](https://cli.github.com/)) and to install Filament. Every question has an option, so it can run unattended:

```bash
winux project:create my-app --git                  # git init + initial commit
winux project:create my-app --github=private       # ...and a private GitHub repo (implies --git; or public)
winux project:create my-app --filament             # install Filament (same as --package=filament)
winux project:create my-app --migrate              # run migrations without asking
winux project:create my-app -n                     # no questions: migrations run; no git, GitHub, packages, editor or browser
```

If git doesn't know your name and email yet (`git config --global user.name` / `user.email`), the repository is created without a commit, and GitHub is skipped, with a note on how to set them.

`project:import` doesn't create repositories or install packages. For a fresh checkout it runs `composer install` (with the site's PHP version) when `vendor/` is missing, and `php artisan key:generate` when `APP_KEY` is empty; if either fails, it stops and says so rather than reporting the site as live. With `--no-interaction` it doesn't run migrations unless you pass `--migrate`, since an imported database may hold data.

When a project's database or user already exists, `project:create` and `project:import` check whether another winux project's `.env` uses it:

- **Another project uses it:** they stop. Since `my-app` and `my_app` both map to the database `my_app`, creating or importing the second one won't share (and reset the password of) the first one's database.
- **Nothing uses it:** it was left behind by a deleted project with the same name (`project:delete` keeps the database unless you ask). They offer to pick it back up, data included, and give the user a new password; this is the default answer, including with `--no-interaction`.

Only projects with a winux site are checked, so a database used by something else entirely isn't detected. `postgres:create` always refuses an existing database or user.

Import is safe to run on a project that's already set up, or one that's half set up:

- **Database:** if the credentials in `.env` already work, they're used as they are and the database isn't touched. If the database named in `.env` exists but the login fails, winux keeps that database. It then creates the user, or offers to reset the user's password. Only when nothing exists does it create a database and user named after the project. winux never changes the `postgres` user or any other superuser, so if `.env` uses one and its login fails, import stops and asks you to fix `DB_PASSWORD` or switch to an app-specific user. A database already owned by another user keeps its owner; the app's user is granted access to it.
- **`.env`:** import always updates `APP_URL` and any `DB_*` values it changed. Other settings (`APP_NAME`, cache, session, queue) are only filled in when they're missing.
- **nginx:** an existing site config is kept. Its PHP version only changes if you pass `--php`.

`project:delete` lists exactly what it will remove before asking: the site (and hosts entries for its extra domains) and the certificate. It then asks separately whether to drop the database and user, and whether to delete the project directory; both default to no, and `--delete-database` / `--delete-files` say yes up front. The database and user are the ones in the project's `.env` (or the ones named after the project when there's no `.env`). The `postgres` and `template*` databases and superuser roles are never dropped, and a database that isn't on a winux Postgres cluster is left alone. It still cleans up if the project directory is already gone. Use `--force` to skip the confirmations (it's required with `--no-interaction`, which keeps the database and files unless you pass the flags).

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

winux site:secure --site=my-app                   # reissue a site's certificate
winux site:secure --all                           # reissue every site's certificate
winux site:permissions                            # hand files (e.g. written by www-data) back to you
```

The `nginx:enable`, `nginx:disable` and `nginx:delete` commands take `--site=<name>` too (or the name as an argument, as before).

Before `site:php` switches versions, it runs the project's `vendor/composer/platform_check.php` with the new PHP version. If the locked dependencies need a different PHP (a project created on 8.4 typically needs 8.4 or newer), every request would fail with "Composer detected issues in your platform", so it shows the problem and asks first (the default is no). Pass `--force` to switch anyway, then run `composer update` with the new version.

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
winux service:configure           # run nginx and all PHP-FPM versions as you (see above)
winux nginx:restart | php:restart [version] | postgres:restart [version] | redis:restart
```

Services are managed with `systemctl`. If systemd isn't running, winux falls back to `service` (and to `pg_ctlcluster` for Postgres clusters), and starts services right after installing them, since nothing starts them at boot.

## SSL

Certificates are issued by mkcert using the CA in `~/.winux/ssl/root`. If you used an older version of winux, its existing `winux_root` CA is adopted automatically, so browsers that already trust it keep working.

```bash
winux ssl:root:install                  # (re)trust the root CA in Linux and Windows
winux ssl:root:install --skip-windows   # only inside Linux/WSL
```

If trusting the CA in Windows fails (Windows isn't reachable, or its prompt is declined), you get a warning and the next step instead of an error: sites still work inside WSL, and only browsers on Windows warn until you run `winux ssl:root:install` again. winux only talks to Windows inside a real WSL distro (one with Windows interop), not in, say, a Docker container on a WSL kernel.

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
- nginx and PHP-FPM now run as you rather than `www-data`. Run `winux service:configure` once (see [above](#nginx-and-php-fpm-run-as-you)).
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
