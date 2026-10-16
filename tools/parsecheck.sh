#!/usr/bin/env bash
# Faoxima PHP parse-check — lint every tracked PHP file and dry-run compile
# the refactored sections exactly like the app does at request time.
#
# Usage: tools/parsecheck.sh
#
set -euo pipefail

REPO_DIR="/opt/faoxima"
APP_CONTAINER="${FAOXIMA_APP_CONTAINER:-faoxima-app-1}"
cd "$REPO_DIR"

fail=0
sections=(re/rx/index re/rx/admin re/rx/function re/rx/keyboard)

files=()
while IFS= read -r f; do
    files+=("$f")
done < <(git ls-files '*.php')

echo "🔍 parsecheck: lint $(printf '%s\n' "${files[@]}" | wc -l) php files (container: ${APP_CONTAINER})"

set +e
lint_out=$(printf '%s\0' "${files[@]}" | docker exec -i -u www-data -w /var/www/faoxima "$APP_CONTAINER" xargs -0 -n 50 php -l 2>&1)
lint_rc=$?
set -e

lint_errors=$(printf '%s\n' "$lint_out" | grep -v "No syntax errors detected" | grep -v '^$' || true)
if [ "$lint_rc" -ne 0 ] || [ -n "$lint_errors" ]; then
    echo "❌ php lint failed:"
    printf '%s\n' "$lint_errors"
    fail=1
else
    echo "✅ php lint ok"
fi

for sec in "${sections[@]}"; do
    if docker exec -u www-data -w /var/www/faoxima "$APP_CONTAINER" php -r 'require "re/_error_log.php"; rx_compile_section($argv[1]); echo "ok\n";' -- "$sec" >/dev/null 2>&1; then
        echo "✅ section compile ok: $sec"
    else
        echo "❌ section compile FAILED: $sec"
        docker exec -u www-data -w /var/www/faoxima "$APP_CONTAINER" php -r 'require "re/_error_log.php"; rx_compile_section($argv[1]); echo "ok\n";' -- "$sec" || true
        fail=1
    fi
done

if [ "$fail" -ne 0 ]; then
    echo "❌ parsecheck failed"
    exit 1
fi
echo "✅ parsecheck: all green"
