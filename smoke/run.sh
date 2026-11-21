#!/usr/bin/env bash
# Live smoke driver: brings up a Dockware Shopware 6.7 store, installs the plugin
# from this working tree and runs smoke.php inside the container.
#
# Usage:
#   BELIQ_API_KEY=... BELIQ_BASE_URL=https://api.beliq.eu ./run.sh   # the real run
#   ./run.sh stub      # the store side only, against stub-router.php on the host
#   ./run.sh down      # tear the stack down and wipe its volumes
#
# BELIQ_BASE_URL is resolved from inside the container, so a beliq API on this
# machine is http://host.docker.internal:<port>. The preflight rewrites that
# host back to localhost before probing. The stack is left up after a run.
set -euo pipefail

cd "$(dirname "$0")"

SHOP_URL="http://localhost:8090"
DC="docker compose --project-directory . -p beliq-shopware-smoke"
PLUGIN_DIR=/var/www/html/custom/plugins/BeliqShopware
# The files a merchant installs: what .gitattributes leaves in the archive.
SHIPPED="composer.json src README.md CHANGELOG.md LICENSE"

if [[ "${1:-}" == "down" ]]; then
    $DC down -v
    exit 0
fi

export BELIQ_SMOKE_STUB=0
if [[ "${1:-}" == "stub" ]]; then
    STUB_PORT="${BELIQ_STUB_PORT:-8096}"
    php -S "0.0.0.0:$STUB_PORT" stub-router.php >/dev/null 2>&1 &
    STUB_PID=$!
    trap 'kill "$STUB_PID" 2>/dev/null' EXIT
    export BELIQ_API_KEY=blq_stub
    export BELIQ_BASE_URL="http://host.docker.internal:$STUB_PORT"
    export BELIQ_SMOKE_STUB=1
    echo "== Stub beliq API on :$STUB_PORT =="
else
    : "${BELIQ_API_KEY:?export BELIQ_API_KEY (a key for the API that BELIQ_BASE_URL names)}"
    : "${BELIQ_BASE_URL:?export BELIQ_BASE_URL (https://api.beliq.eu for production)}"
    export BELIQ_API_KEY BELIQ_BASE_URL
fi

echo "== Preflight: beliq API reachable =="
PREFLIGHT_URL="${BELIQ_BASE_URL/host.docker.internal/localhost}/health/live"
for _ in $(seq 1 10); do
    curl -fsS -m 10 "$PREFLIGHT_URL" >/dev/null 2>&1 && break
    sleep 1
done
if ! curl -fsS -m 10 "$PREFLIGHT_URL" >/dev/null; then
    echo "FAIL: beliq API not reachable at $PREFLIGHT_URL" >&2
    exit 1
fi
echo "  ok ($PREFLIGHT_URL)"

echo "== Bringing up the store =="
# Recreate the container so it picks up this run's BELIQ_* environment.
$DC up -d --force-recreate
for _ in $(seq 1 90); do
    code=$(curl -s -o /dev/null -w '%{http_code}' "$SHOP_URL/api/_info/version" || true)
    [[ "$code" == 401 || "$code" == 200 ]] && break
    sleep 2
done
if [[ "$code" != 401 && "$code" != 200 ]]; then
    echo "FAIL: Shopware did not answer at $SHOP_URL (last status $code)" >&2
    exit 1
fi

echo "== Installing the plugin from the working tree =="
$DC exec -T shop bash -c "rm -rf $PLUGIN_DIR && mkdir -p $PLUGIN_DIR && cd /plugin-src && cp -r $SHIPPED $PLUGIN_DIR/"
$DC exec -T shop bin/console plugin:refresh -q
$DC exec -T shop bin/console plugin:install --activate --reinstall BeliqShopware -q
$DC exec -T shop bin/console cache:clear -q

echo "== Running the smoke on plugin tree $(git -C .. rev-parse --short HEAD)$(git -C .. diff --quiet HEAD -- || echo ' with uncommitted changes') =="
set +e
$DC exec -T shop php /smoke/smoke.php
code=$?
set -e

echo ""
if [[ $code -eq 0 ]]; then
    echo "SMOKE PASSED. Store left running at $SHOP_URL/admin (admin/shopware). Tear down with: ./run.sh down"
else
    echo "SMOKE FAILED (exit $code). Store left up at $SHOP_URL/admin (admin/shopware). Tear down with: ./run.sh down" >&2
fi
exit $code
