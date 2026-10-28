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

`install.sh` adds the PHP repository ([ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php) on Ubuntu, [sury](https://packages.sury.org/php/) on Debian), installs a PHP CLI and Composer, and puts the `winux` binary in `/usr/local/bin`. Set `WINUX_PHP=8.3` or `WINUX_VERSION=v2.0.0` to override the defaults.

`winux setup` then asks which PHP versions and which Postgres version you want, and installs:

- the [PGDG](https://www.postgresql.org/download/linux/ubuntu/) Postgres repository and your chosen Postgres version (match production)
- nginx, Redis, and the chosen PHP-FPM versions with common extensions
- [mkcert](https://github.com/FiloSottile/mkcert), with its root CA trusted by Linux and by Windows (Chrome/Edge/Firefox on Windows)

Run `winux doctor` at any time to check that everything is healthy.

Update later with `winux self-update`.

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
├── config.json       # php.default, postgres.default
├── sites/
│   ├── available/    # nginx site configs, named after the primary domain
│   └── enabled/      # symlinks to enabled sites (included from /etc/nginx/nginx.conf)
├── ssl/
│   ├── root/         # mkcert CAROOT (rootCA.pem, rootCA-key.pem)
│   └── certs/        # per-site certificates
└── backups/          # configs saved before winux edits them
```

## Upgrading from 1.x

- Your sites, certificates, and root CA are all kept. Run `winux doctor` to check everything.
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

Releases are built by GitHub Actions when a `v*` tag is pushed.

## Support the development

- GitHub: [Sponsor](https://github.com/sponsors/chrisreedio)

## Credits

- [Chris Reed](https://github.com/chrisreedio)
- [All Contributors](../../contributors)

## License

Winux CLI is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE).
