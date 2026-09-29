#!/bin/bash

# Installer for the epoll extension on Debian Trixie
# and compatible systems: Ubuntu 24.04+, Raspberry Pi OS Bookworm/Trixie.
#
# - Resolves PHP binary, php-config and extension dir automatically
# - Syncs epoll_arginfo.h with epoll.stub.php, then compiles with phpize
# - Installs epoll.so, enables it for CLI, FPM, and Apache SAPIs
# - Leaves the checkout clean: build artifacts are removed after install

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXTENSION_NAME="epoll"
BUILD_SO="${SCRIPT_DIR}/modules/${EXTENSION_NAME}.so"
LOG_FILE="${SCRIPT_DIR}/build.log"

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    SUDO="sudo"
else
    SUDO=""
fi

die()  { echo ""; echo "❌ $*"; exit 1; }
step() { echo "$*"; }
ok()   { echo "   ✓ $*"; }

show_failure_logs() {
    if [ -f "$LOG_FILE" ]; then
        echo ""
        echo "---- Last 80 lines of ${LOG_FILE} ----"
        tail -80 "$LOG_FILE" || true
    fi
}

# phpize builds in the package root. Remove everything phpize/configure/make
# generate (see .gitignore), escalating to sudo for root-owned leftovers.
clean_build_tree() {
    local item used_sudo=0

    for item in \
        .libs autom4te.cache build include modules \
        Makefile Makefile.fragments Makefile.global Makefile.objects \
        acinclude.m4 aclocal.m4 config.guess config.h config.h.in config.h.in~ \
        config.log config.nice config.status config.sub configure configure~ \
        configure.ac install-sh libtool ltmain.sh missing mkinstalldirs run-tests.php
    do
        [ -e "${SCRIPT_DIR}/${item}" ] || continue
        if rm -rf "${SCRIPT_DIR:?}/${item}" 2>/dev/null; then
            continue
        fi
        [ -n "$SUDO" ] || return 1
        $SUDO rm -rf "${SCRIPT_DIR:?}/${item}" || return 1
        used_sudo=1
    done

    if ! find "$SCRIPT_DIR" -maxdepth 1 \( -name '*.o' -o -name '*.lo' -o -name '*.la' -o -name '*.dep' \) -delete 2>/dev/null; then
        [ -n "$SUDO" ] || return 1
        $SUDO find "$SCRIPT_DIR" -maxdepth 1 \( -name '*.o' -o -name '*.lo' -o -name '*.la' -o -name '*.dep' \) -delete || return 1
        used_sudo=1
    fi

    if [ "$used_sudo" -eq 1 ] && [ -n "$SUDO" ]; then
        $SUDO chown -R "$(id -u)":"$(id -g)" "$SCRIPT_DIR" 2>/dev/null || true
    fi
}

echo "=========================================="
echo " epoll Extension Installer (Debian Trixie / Raspberry Pi OS)"
echo "=========================================="
echo ""

# ── Preflight ────────────────────────────────────────────────────────────────
step "🔎 Preflight checks..."
[ "$(uname -s)" = "Linux" ] || die "epoll(7) is Linux-only (this is $(uname -s))."
command -v php >/dev/null 2>&1 || die "php not found in PATH"

PHP_VER_MM="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_VER_ID="$(php -r 'echo PHP_VERSION_ID;')"
[ "$PHP_VER_ID" -ge 80400 ] || die "PHP >= 8.4 required (found ${PHP_VER_MM})."

command -v cc >/dev/null 2>&1 || die "cc not found — install: apt install build-essential"
echo '#include <sys/epoll.h>' | cc -E -x c - >/dev/null 2>&1 || die "sys/epoll.h not found — install: apt install libc6-dev"

PHP_BIN_REAL="$(php -r 'echo PHP_BINARY;' 2>/dev/null)"
PHP_BIN_DIR="$(dirname "$PHP_BIN_REAL")"

_PHP_VER_NODOT="${PHP_VER_MM//./}"
RESOLVED_PHP_CONFIG=""
RESOLVED_PHPIZE=""
for _candidate in \
    "${PHP_BIN_DIR}/php-config${_PHP_VER_NODOT}" \
    "${PHP_BIN_DIR}/php-config${PHP_VER_MM}" \
    "${PHP_BIN_DIR}/php-config" \
    "$(command -v "php-config${PHP_VER_MM}" 2>/dev/null || true)" \
    "$(command -v php-config 2>/dev/null || true)"
do
    if [ -n "$_candidate" ] && [ -x "$_candidate" ]; then
        RESOLVED_PHP_CONFIG="$_candidate"
        break
    fi
done
[ -n "$RESOLVED_PHP_CONFIG" ] || die "Could not locate php-config. Install php-dev (apt install php${PHP_VER_MM}-dev)."

for _candidate in \
    "${PHP_BIN_DIR}/phpize${_PHP_VER_NODOT}" \
    "${PHP_BIN_DIR}/phpize${PHP_VER_MM}" \
    "${PHP_BIN_DIR}/phpize" \
    "$(command -v "phpize${PHP_VER_MM}" 2>/dev/null || true)" \
    "$(command -v phpize 2>/dev/null || true)"
do
    if [ -n "$_candidate" ] && [ -x "$_candidate" ]; then
        RESOLVED_PHPIZE="$_candidate"
        break
    fi
done
[ -n "$RESOLVED_PHPIZE" ] || die "Could not locate phpize. Install php-dev (apt install php${PHP_VER_MM}-dev)."

[ "$("$RESOLVED_PHP_CONFIG" --vernum)" -eq "$PHP_VER_ID" ] || \
    die "php-config (${RESOLVED_PHP_CONFIG}) targets $("$RESOLVED_PHP_CONFIG" --version), but php is ${PHP_VER_MM}."

PHP_EXT_DIR="$("$RESOLVED_PHP_CONFIG" --extension-dir 2>/dev/null)" \
    || die "Could not determine PHP extension dir from php-config."
[ -n "$PHP_EXT_DIR" ] || die "Could not determine PHP extension dir."

CLI_SCAN_DIR="$(php --ini 2>/dev/null | awk -F': ' '/Scan for additional \.ini files in:/{print $2}' || true)"

ok "PHP version:   ${PHP_VER_MM}"
ok "PHP binary:    ${PHP_BIN_REAL}"
ok "php-config:    ${RESOLVED_PHP_CONFIG}"
ok "phpize:        ${RESOLVED_PHPIZE}"
ok "Extension dir: ${PHP_EXT_DIR}"
echo ""

# ── Compile ───────────────────────────────────────────────────────────────────
cd "${SCRIPT_DIR}"

step "🧹 Resetting build tree..."
clean_build_tree || die "Could not remove build artifacts from ${SCRIPT_DIR} (root-owned). Run: sudo chown -R \$(id -un):\$(id -gn) ${SCRIPT_DIR}"
ok "Build tree reset"
echo ""

step "   Compiling..."
: >"$LOG_FILE"
if ! "$RESOLVED_PHPIZE" >>"$LOG_FILE" 2>&1; then
    show_failure_logs
    die "phpize failed. See ${LOG_FILE}."
fi
# No-op while the stub hash matches; regenerates arginfo after a stub edit.
if ! "$PHP_BIN_REAL" build/gen_stub.php epoll.stub.php >>"$LOG_FILE" 2>&1; then
    show_failure_logs
    die "gen_stub.php failed. See ${LOG_FILE}."
fi
if ! ./configure --enable-epoll "--with-php-config=${RESOLVED_PHP_CONFIG}" >>"$LOG_FILE" 2>&1; then
    show_failure_logs
    die "configure failed. See ${LOG_FILE}."
fi
if ! make -j"$(nproc 2>/dev/null || echo 4)" >>"$LOG_FILE" 2>&1; then
    show_failure_logs
    die "make failed. See ${LOG_FILE}."
fi

if [ ! -f "$BUILD_SO" ]; then
    show_failure_logs
    die "Build output not found at ${BUILD_SO}."
fi
ok "Build complete"
echo ""

# ── Install .so ───────────────────────────────────────────────────────────────
step "📦 Installing binary..."
$SUDO mkdir -p "$PHP_EXT_DIR"
$SUDO cp -f "$BUILD_SO" "${PHP_EXT_DIR}/${EXTENSION_NAME}.so"
$SUDO chmod 755 "${PHP_EXT_DIR}/${EXTENSION_NAME}.so"
ok "Copied to: ${PHP_EXT_DIR}/${EXTENSION_NAME}.so"

clean_build_tree || die "Installed, but could not remove build artifacts from ${SCRIPT_DIR}."
ok "Build artifacts removed"
echo ""

# ── Enable across SAPIs ───────────────────────────────────────────────────────
step "⚙️  Enabling extension..."
declare -a CONF_DIR_CANDIDATES=()

# Only the conf.d dirs of the PHP we built against: another PHP on the box
# (distro vs /usr/local build) would fail to load this epoll.so.
if [ -n "${CLI_SCAN_DIR:-}" ] && [ "$CLI_SCAN_DIR" != "(none)" ] && [ -d "$CLI_SCAN_DIR" ]; then
    CONF_DIR_CANDIDATES+=("$CLI_SCAN_DIR")
    # Debian/Ubuntu packaging: FPM and Apache SAPIs of the same PHP sit beside cli/.
    if [ "$CLI_SCAN_DIR" = "/etc/php/${PHP_VER_MM}/cli/conf.d" ]; then
        for d in "/etc/php/${PHP_VER_MM}/fpm/conf.d" "/etc/php/${PHP_VER_MM}/apache2/conf.d"; do
            [ -d "$d" ] && CONF_DIR_CANDIDATES+=("$d")
        done
    fi
fi

CONF_DIRS=()
while IFS= read -r _line; do
    [ -n "$_line" ] && CONF_DIRS+=("$_line")
done < <(printf "%s\n" "${CONF_DIR_CANDIDATES[@]:-}" | awk '!seen[$0]++')

[ "${#CONF_DIRS[@]}" -eq 0 ] && echo "   ⚠️  No conf.d directories found — add extension=${EXTENSION_NAME}.so to php.ini."

INI_NAME="30-${EXTENSION_NAME}.ini"
INI_CONTENT="extension=${EXTENSION_NAME}.so"
for confd in "${CONF_DIRS[@]:-}"; do
    [ -n "$confd" ] || continue
    INI_PATH="${confd}/${INI_NAME}"
    echo "$INI_CONTENT" | $SUDO tee "$INI_PATH" >/dev/null
    ok "Written: $INI_PATH"
done
echo ""

# ── Verify ────────────────────────────────────────────────────────────────────
step "🔍 Verifying installation (CLI)..."
if ! "$PHP_BIN_REAL" -r 'exit(function_exists("epoll_create1") ? 0 : 1);' 2>/dev/null; then
    "$PHP_BIN_REAL" -m 2>&1 | grep -i "${EXTENSION_NAME}\|error\|warn" || true
    "$PHP_BIN_REAL" --ini 2>/dev/null | grep -E "Scan for additional|Additional \.ini" || true
    die "epoll_create1() not available after install. Check ${INI_NAME} and ${LOG_FILE}."
fi
ok "epoll_create1() is available in CLI"
echo ""

# ── FPM reload ───────────────────────────────────────────────────────────────
if command -v systemctl >/dev/null 2>&1; then
    for svc in "php${PHP_VER_MM}-fpm" "php-fpm"; do
        if systemctl is-active --quiet "${svc}.service" 2>/dev/null; then
            step "🔁 Reloading ${svc}..."
            $SUDO systemctl reload "${svc}" || true
            ok "${svc} reloaded"
            break
        fi
    done
fi

step "=========================================="
step " Extension Information (CLI)"
step "=========================================="
"$PHP_BIN_REAL" --ri "${EXTENSION_NAME}" || true
echo ""

echo "✅  Installation complete!"
echo ""
echo "File locations:"
echo "  • Binary: ${PHP_EXT_DIR}/${EXTENSION_NAME}.so"
for d in "${CONF_DIRS[@]:-}"; do
    [ -n "$d" ] && echo "  • Config: ${d}/${INI_NAME}"
done
echo "  • Log:    ${LOG_FILE}"
echo ""
