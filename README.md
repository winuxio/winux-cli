<p align="center">
	<img title="Winux CLI" height="200" src="https://github.com/winuxio/winux-cli/assets/77644584/60f6b48a-1fe5-4350-8b9e-0bdc2ceed64a" />
</p>

<p align="center">
    <a href="https://github.com/winuxio/winux-cli/actions/workflows/laravel-zero-build.yml"><img src="https://github.com/winuxio/winux-cli/actions/workflows/laravel-zero-build.yml/badge.svg" alt="Build Status"></a>
    <img alt="Latest Release" src="https://img.shields.io/github/v/release/winuxio/winux-cli">
	<a href="https://discord.gg/ZWJQ22dX3b"><img alt="Discord" src="https://img.shields.io/discord/1137287672944144385"></a>
</p>

<h4><center><bold>Simplify</bold> local dev ops for Windows/WSL</center></h4>

Winux automates nginx setup, database creation, and environment configuration, getting you ready to code in 30 seconds.

- **Simple** - Winux is designed to be simple to use and easy to understand.
- **Powerful** - Winux is powered by the [Laravel Zero](https://laravel-zero.com/) framework.
- **Flexible** - Winux is designed to work with many different frameworks and languages.
- **Fast** - Winux is designed to be fast, and to get you coding in 30 seconds.
- **Windows** - Winux is designed to improve the WSL/Windows development experience.

## Prerequisites

Before installing Winux, ensure you have the following installed in your WSL environment:

- **WSL 2** with Ubuntu (or another Debian-based distribution)
- **PHP 8.1+** (PHP 8.2 recommended)
- **Composer** (v2)
- **OpenSSL** (for SSL certificate generation)

## Quick Start

### Option 1: Download Pre-built Binary (Recommended)

Download the latest release from the [Releases page](https://github.com/winuxio/winux-cli/releases) and place it in your PATH.

### Option 2: Install from Source

1. **Clone the repository**
   ```bash
   git clone https://github.com/winuxio/winux-cli.git
   cd winux-cli
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Run Winux directly (development mode)**
   ```bash
   php winux
   ```

4. **Or build and install system-wide**
   ```bash
   # Build the PHAR executable
   php winux app:build

   # Install to /usr/local/bin (requires sudo)
   php winux app:install:local
   ```

### Initial Setup

After installation, set up your development stack:

```bash
# Install the full stack (nginx, PHP-FPM, PostgreSQL, Redis)
winux service:install

# Or install services individually
winux service:install nginx
winux service:install php
winux service:install postgres
winux service:install redis
```

### Create Your First Project

```bash
winux project:create my-awesome-app
```

This will:
- Create a new Laravel project
- Configure nginx with SSL
- Create a PostgreSQL database
- Set up your `.env` file
- Optionally create a GitHub repository
- Open the project in your preferred editor

Your site will be available at `https://my-awesome-app.local.winux.io`

## Available Commands

### Project Management
| Command | Description |
|---------|-------------|
| `project:create <name>` | Create a new project with full stack setup |
| `project:import` | Import an existing project |
| `project:delete` | Delete a project and its resources |

### Service Management
| Command | Description |
|---------|-------------|
| `service:install [service]` | Install services (nginx, php, postgres, mysql, redis) |
| `service:start [service]` | Start a service |
| `service:stop [service]` | Stop a service |
| `service:restart [service]` | Restart a service |
| `service:status` | Check status of all services |

### Nginx
| Command | Description |
|---------|-------------|
| `nginx:create` | Create a new nginx site configuration |
| `nginx:delete` | Delete an nginx site |
| `nginx:enable` | Enable an nginx site |
| `nginx:disable` | Disable an nginx site |
| `nginx:restart` | Restart nginx |

### Database (PostgreSQL)
| Command | Description |
|---------|-------------|
| `postgres:create-database` | Create a new PostgreSQL database |
| `postgres:drop-database` | Drop a PostgreSQL database |
| `postgres:restart` | Restart PostgreSQL service |

### SSL/TLS
| Command | Description |
|---------|-------------|
| `ssl:install-root-ca` | Install Winux root certificate authority |

### Other
| Command | Description |
|---------|-------------|
| `php:restart-fpm` | Restart PHP-FPM service |
| `redis:restart` | Restart Redis service |
| `account:login` | Login to your Winux account |

## Development

### Running Tests

```bash
composer test
# or
./vendor/bin/pest
```

### Code Style

This project uses Laravel Pint for code formatting:

```bash
composer lint
# or
./vendor/bin/pint
```

### Building

To build a PHAR executable:

```bash
php winux app:build
```

The built binary will be available in the `builds/` directory.

## Configuration

Winux stores its configuration and site data in `~/.winux/`. The directory structure includes:

```
~/.winux/
├── sites/
│   ├── available/    # All site configurations
│   └── enabled/      # Symlinks to enabled sites
└── ssl/              # SSL certificates
```

For full documentation, visit the [documentation](https://docs.winux.io/).

## Support the development
**Do you like this project? Support it by donating**

- GitHub: [Sponsor](https://github.com/sponsors/chrisreedio)

## Credits

- [Chris Reed](https://github.com/chrisreedio)
- [All Contributors](../../contributors)

## License

The License choice for Winux CLI is still pending.