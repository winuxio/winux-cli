#!/usr/bin/env bash
#
# Winux installer for WSL2 / Ubuntu / Debian.
#
#   curl -fsSL https://raw.githubusercontent.com/winuxio/winux-cli/main/install.sh | bash
#
# Environment overrides:
#   WINUX_PHP=8.4          PHP version for the winux CLI itself (8.3 or newer; also the default for new sites)
#   WINUX_VERSION=latest   Release tag to install, e.g. v0.2.0
#   WINUX_BIN=/usr/local/bin/winux
#
set -euo pipefail

REPO="winuxio/winux-cli"
PHP_VERSION="${WINUX_PHP:-8.4}"
PHP_MINIMUM="8.3"
WINUX_VERSION="${WINUX_VERSION:-latest}"
WINUX_BIN="${WINUX_BIN:-/usr/local/bin/winux}"
# Every release from this version on publishes winux.sha256
CHECKSUMS_SINCE="0.2.0"

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2; }
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# version_lt 8.2 8.3: true when the first version is older than the second
version_lt() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$1" ]; }

# Everything runs from main, called on the last line, so a download cut short by a dropped
# connection can't run half the script. (The body isn't indented, to keep diffs readable.)
main() {

[[ "$PHP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]] || fail "WINUX_PHP must be a PHP version like 8.4, not '$PHP_VERSION'."
version_lt "$PHP_VERSION" "$PHP_MINIMUM" && fail "winux needs PHP $PHP_MINIMUM or newer, but WINUX_PHP is $PHP_VERSION. Use WINUX_PHP=8.4 (sites can still use older PHP versions)."
PHP_BIN="php${PHP_VERSION}"

[ "$(id -u)" -eq 0 ] && fail "Run this as your normal user (it uses sudo when needed), not as root."
command -v sudo >/dev/null || fail "sudo is required."
command -v apt-get >/dev/null || fail "winux supports Debian-based distributions (Ubuntu, Debian) only."

# shellcheck disable=SC1091
. /etc/os-release
DISTRO="${ID:-unknown}"
CODENAME="${VERSION_CODENAME:-${UBUNTU_CODENAME:-}}"
PRETTY_NAME="${PRETTY_NAME:-$DISTRO}"

case "$DISTRO" in
	ubuntu|debian) ;;
	*) fail "Unsupported distribution: ${PRETTY_NAME:-$DISTRO}. winux supports Ubuntu and Debian." ;;
esac

# A real WSL distro (with Windows interop), not just a WSL kernel: Docker containers on WSL
# report "microsoft" in /proc/version too
is_wsl() {
	[ -n "${WSL_DISTRO_NAME:-}" ] || [ -d /run/WSL ] || compgen -G '/proc/sys/fs/binfmt_misc/WSLInterop*' >/dev/null
}

if is_wsl; then
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
		|| compgen -G '/etc/apt/sources.list.d/*ondrej-ubuntu-php*' >/dev/null
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

command -v "$PHP_BIN" >/dev/null || fail "$PHP_BIN wasn't installed. Check the apt output above."

# The winux binary runs with whichever `php` is first on PATH
path_php="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || true)"
if [ -z "$path_php" ] || version_lt "$path_php" "$PHP_MINIMUM"; then
	warn "'php' on your PATH is ${path_php:+PHP }${path_php:-missing}, but winux needs PHP $PHP_MINIMUM or newer. Point it at PHP ${PHP_VERSION} with:"
	printf '\n    sudo update-alternatives --set php /usr/bin/%s\n\n' "$PHP_BIN" >&2
fi

if ! command -v composer >/dev/null; then
	info "Installing Composer..."
	tmp_dir="$(mktemp -d)"
	expected="$(curl -fsSL https://composer.github.io/installer.sig)"
	curl -fsSLo "$tmp_dir/composer-setup.php" https://getcomposer.org/installer
	actual="$("$PHP_BIN" -r "echo hash_file('sha384', '$tmp_dir/composer-setup.php');")"
	[ "$expected" = "$actual" ] || { rm -rf "$tmp_dir"; fail "Composer installer checksum mismatch."; }
	"$PHP_BIN" "$tmp_dir/composer-setup.php" --quiet --install-dir="$tmp_dir"
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

# Required, except for a pinned release that predates checksums. Only a 404 means "no checksum":
# any other failure (network, 5xx) stops the install rather than skipping verification.
checksum_status="$(curl -sSL -o "$tmp_dir/winux.sha256" -w '%{http_code}' "$base_url/winux.sha256")" || checksum_status="000"
case "$checksum_status" in
	200)
		(cd "$tmp_dir" && sha256sum --check --status winux.sha256) || fail "Checksum mismatch: the download may be corrupt. Try again."
		info "Checksum verified"
		;;
	404)
		if [ "$WINUX_VERSION" != "latest" ] && version_lt "${WINUX_VERSION#v}" "$CHECKSUMS_SINCE"; then
			warn "winux ${WINUX_VERSION} predates published checksums; skipping verification."
		else
			fail "winux ${WINUX_VERSION} has no published checksum ($base_url/winux.sha256), so the download can't be verified."
		fi
		;;
	*)
		fail "Couldn't download the checksum from $base_url/winux.sha256 (HTTP $checksum_status). Try again."
		;;
esac

"$PHP_BIN" "$tmp_dir/winux" --version >/dev/null || fail "The downloaded winux binary failed to run with $PHP_BIN."
sudo install -m 0755 "$tmp_dir/winux" "$WINUX_BIN"

# Earlier versions installed to /usr/sbin
if [ -f /usr/sbin/winux ] && [ "$WINUX_BIN" != /usr/sbin/winux ]; then
	answer=""
	# stdin is this script under `curl | bash`, so ask on the terminal (if there is one).
	# Only silence the check: read prints its prompt to stderr, so hiding read's stderr hides the question
	if { : </dev/tty; } 2>/dev/null; then
		read -r -p "Remove the older winux at /usr/sbin/winux? [Y/n] " answer </dev/tty || answer="skip"
	else
		answer="skip"
	fi
	case "$answer" in
		""|[Yy]*) sudo rm -f /usr/sbin/winux && info "Removed /usr/sbin/winux" ;;
		*) warn "An older winux remains at /usr/sbin/winux. Remove it with: sudo rm /usr/sbin/winux" ;;
	esac
fi

info "Installed winux $("$WINUX_BIN" --version --no-ansi 2>/dev/null | grep -oE 'v?[0-9]+\.[0-9]+\.[0-9][^ ]*' | head -n1) to $WINUX_BIN"
echo
if [ -d "$HOME/.winux/sites" ]; then
	echo "Your existing sites and certificates are kept. Check everything is healthy with:"
	echo
	echo "    winux doctor"
else
	echo "Next, set up your stack (nginx, PHP, Postgres, Redis, SSL):"
	echo
	echo "    winux setup"
fi
echo
echo "Later, update with: winux self-update"
echo

}

main "$@"
