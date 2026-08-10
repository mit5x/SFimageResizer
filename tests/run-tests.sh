#!/usr/bin/env bash
#
# Runs the whole SFimageResizer test suite.
#
# Required environment:
#   SFIR_WP_DIR    absolute path of a WordPress installation with the plugin active
#   SFIR_WP_CLI    wp-cli command or path to wp-cli.phar (default: "wp")
#   SFIR_BASE_URL  base URL of a web server serving that installation
#   SFIR_ADMIN_USER / SFIR_ADMIN_PASS  administrator credentials (default: admin / admin123)
#   SFIR_PHPUNIT   phpunit command (default: "phpunit")
#   SFIR_PHPCS     phpcs command (default: "phpcs")
#
set -u

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"

WP_DIR="${SFIR_WP_DIR:-}"
WP_CLI="${SFIR_WP_CLI:-wp}"
BASE_URL="${SFIR_BASE_URL:-}"
PHPUNIT="${SFIR_PHPUNIT:-phpunit}"
PHPCS="${SFIR_PHPCS:-phpcs}"

export SFIR_BASE_URL="$BASE_URL"
export SFIR_ADMIN_USER="${SFIR_ADMIN_USER:-admin}"
export SFIR_ADMIN_PASS="${SFIR_ADMIN_PASS:-admin123}"

failures=0

# wp-cli may be a phar, a wrapper script, or a plain command. Resolve one form
# that can always be handed to the PHP binary, so that the WebP fallback test
# can run with imagewebp() disabled.
WP_CLI_PATH="$( command -v "$WP_CLI" 2>/dev/null || echo "$WP_CLI" )"

if [ -x "$WP_CLI_PATH" ] && [ "${WP_CLI##*.}" != "phar" ]; then
	WP_RUN=( "$WP_CLI_PATH" )
else
	WP_RUN=( php "$WP_CLI_PATH" )
fi

run_step() {
	echo
	echo "======================================================================"
	echo "== $1"
	echo "======================================================================"
	shift
	"$@"
	local status=$?
	if [ $status -ne 0 ]; then
		failures=$(( failures + 1 ))
	fi
	return $status
}

# 11.1 - standalone unit tests, no WordPress required.
run_step "11.1 unit tests (PHPUnit)" "$PHPUNIT" --configuration "$ROOT/phpunit.xml.dist"

# WordPress Coding Standards.
run_step "PHPCS / WordPress Coding Standards" "$PHPCS" --standard="$ROOT/phpcs.xml.dist" --report=summary

if [ -z "$WP_DIR" ] || [ -z "$BASE_URL" ]; then
	echo
	echo "SFIR_WP_DIR or SFIR_BASE_URL is not set: skipping the integration suite."
	exit $(( failures > 0 ))
fi

cd "$WP_DIR" || exit 1

# 11.2.1 - 11.2.11
run_step "11.2 integration suite" "${WP_RUN[@]}" eval-file "$ROOT/tests/integration/run.php" --allow-root

# 11.2.10 admin screen, driven over HTTP
run_step "11.2.10 admin screen" "${WP_RUN[@]}" eval-file "$ROOT/tests/integration/admin.php" --allow-root

# 11.2.12 WebP fallback, in a process where imagewebp() is disabled
run_step "11.2.12 WebP fallback" php -d disable_functions=imagewebp "$WP_CLI_PATH" eval-file "$ROOT/tests/integration/webp-fallback.php" --allow-root

# 11.2.13 activation / deactivation / uninstall
run_step "11.2.13 lifecycle" "${WP_RUN[@]}" eval-file "$ROOT/tests/integration/lifecycle.php" --allow-root

# 11.3 Plugin Check
run_step "11.3 Plugin Check" "${WP_RUN[@]}" plugin check sf-image-resizer \
	--categories=general,plugin_repo,security,performance,accessibility \
	--include-experimental --allow-root

echo
echo "======================================================================"
if [ $failures -eq 0 ]; then
	echo "== All steps passed."
else
	echo "== $failures step(s) failed."
fi
echo "======================================================================"

exit $(( failures > 0 ))
