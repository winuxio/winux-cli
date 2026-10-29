#!/usr/bin/env bash
#
# Winux installer for WSL2 / Ubuntu / Debian.
#
#   curl -fsSL https://raw.githubusercontent.com/winuxio/winux-cli/main/install.sh | bash
#
# Environment overrides:
#   WINUX_PHP=8.4          PHP version for the winux CLI itself (and the default for new sites)
#   WINUX_VERSION=latest   Release tag to install, e.g. v0.2.0
#   WINUX_BIN=/usr/local/bin/winux
#
set -euo pipefail

REPO="winuxio/winux-cli"
PHP_VERSION="${WINUX_PHP:-8.4}"
WINUX_VERSION="${WINUX_VERSION:-latest}"
WINUX_BIN="${WINUX_BIN:-/usr/local/bin/winux}"

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] && fail "Run this as your normal user (it uses sudo when needed), not as root."
command -v sudo >/dev/null || fail "sudo is required."
command -v apt-get >/dev/null || fail "winux supports Debian-based distributions (Ubuntu, Debian) only."

# shellcheck disable=SC1091
. /etc/os-release
DISTRO="${ID:-unknown}"
CODENAME="${VERSION_CODENAME:-${UBUNTU_CODENAME:-}}"

case "$DISTRO" in
	ubuntu|debian) ;;
	*) fail "Unsupported distribution: ${PRETTY_NAME:-$DISTRO}. winux supports Ubuntu and Debian." ;;
esac

if grep -qi microsoft /proc/version 2>/dev/null; then
	info "Detected WSL (${PRETTY_NAME})"
	if [ ! -d /run/systemd/system ]; then
		warn "systemd is not running. winux manages services with systemd."
		warn "Add the following to /etc/wsl.conf, then run 'wsl --shutdown' from Windows and reopen your terminal:"
		printf '\n[boot]\nsystemd=true\n\n' >&2
	fi
else
	info "Detected ${PRETTY_NAME}"
fi

info "Installing base packages..."
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ca-certificates curl unzip git gnupg >/dev/null

has_php_repo() {
	grep -rqsE 'ondrej/php|ondrej-ubuntu-php|packages\.sury\.org/php' /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null \
		|| ls /etc/apt/sources.list.d/ 2>/dev/null | grep -q 'ondrej-ubuntu-php'
}

if ! has_php_repo; then
	if [ "$DISTRO" = "ubuntu" ]; then
		info "Adding the ondrej/php PPA..."
		sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq software-properties-common >/dev/null
		sudo add-apt-repository -y ppa:ondrej/php >/dev/null
	else
		info "Adding the packages.sury.org PHP repository..."
		tmp_deb="$(mktemp --suffix=.deb)"
		curl -fsSLo "$tmp_deb" https://packages.sury.org/debsuryorg-archive-keyring.deb
		sudo dpkg -i "$tmp_deb" >/dev/null
		rm -f "$tmp_deb"
		echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" \
			| sudo tee /etc/apt/sources.list.d/php.list >/dev/null
	fi
	sudo apt-get update -qq
fi

info "Installing PHP ${PHP_VERSION} CLI..."
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
	"php${PHP_VERSION}-cli" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-mbstring" \
	"php${PHP_VERSION}-xml" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-intl" >/dev/null

if ! command -v composer >/dev/null; then
	info "Installing Composer..."
	tmp_dir="$(mktemp -d)"
	expected="$(curl -fsSL https://composer.github.io/installer.sig)"
	curl -fsSLo "$tmp_dir/composer-setup.php" https://getcomposer.org/installer
	actual="$(php -r "echo hash_file('sha384', '$tmp_dir/composer-setup.php');")"
	[ "$expected" = "$actual" ] || { rm -rf "$tmp_dir"; fail "Composer installer checksum mismatch."; }
	php "$tmp_dir/composer-setup.php" --quiet --install-dir="$tmp_dir"
	sudo install -m 0755 "$tmp_dir/composer.phar" /usr/local/bin/composer
	rm -rf "$tmp_dir"
fi

if [ "$WINUX_VERSION" = "latest" ]; then
	base_url="https://github.com/${REPO}/releases/latest/download"
else
	base_url="https://github.com/${REPO}/releases/download/${WINUX_VERSION}"
fi

if [ -x "$WINUX_BIN" ]; then
	info "Replacing the installed winux. Next time, just run: winux self-update"
fi

info "Downloading winux (${WINUX_VERSION})..."
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT
curl -fsSLo "$tmp_dir/winux" "$base_url/winux" || fail "Couldn't download $base_url/winux"

if curl -fsSLo "$tmp_dir/winux.sha256" "$base_url/winux.sha256" 2>/dev/null; then
	(cd "$tmp_dir" && sha256sum --check --status winux.sha256) || fail "Checksum mismatch: the download may be corrupt. Try again."
	info "Checksum verified"
else
	warn "This release has no checksum; skipping verification."
fi

php "$tmp_dir/winux" --version >/dev/null || fail "The downloaded winux binary failed to run."
sudo install -m 0755 "$tmp_dir/winux" "$WINUX_BIN"

# Earlier versions installed to /usr/sbin
if [ -f /usr/sbin/winux ] && [ "$WINUX_BIN" != /usr/sbin/winux ]; then
	warn "An older winux exists at /usr/sbin/winux. Remove it with: sudo rm /usr/sbin/winux"
fi

info "Installed winux $("$WINUX_BIN" --version --no-ansi 2>/dev/null | grep -oE 'v?[0-9]+\.[0-9]+\.[0-9][^ ]*' | head -n1) to $WINUX_BIN"
echo
echo "Next, set up your stack (nginx, PHP, Postgres, Redis, SSL):"
echo
echo "    winux setup"
echo
