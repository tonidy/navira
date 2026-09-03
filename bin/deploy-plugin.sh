#!/usr/bin/env bash
#
# Deploy plugin/nvr-hs into the running navira stack.
#
# The plugin is NOT bind-mounted from this repo on purpose. The repo lives in
# /home/ubuntu, which the container's www-data user cannot traverse, so
# mounting it would recreate exactly the permission failure that took the site
# down when the nginx docroot pointed inside /var/lib/docker. Instead we rsync
# into the webroot, which nginx and php-fpm can both already read.
#
# Usage:  bin/deploy-plugin.sh [--activate]

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${REPO_DIR}/plugin/nvr-hs"
DEST="/srv/navira/wp/wp-content/plugins/nvr-hs"
CONTAINER="navira-wordpress-1"
IN_CONTAINER="/var/www/html/wp-content/plugins/nvr-hs"

[ -f "${SRC}/nvr-hs.php" ] || { echo "error: ${SRC}/nvr-hs.php missing, refusing to deploy" >&2; exit 1; }

echo "==> rsync"
sudo mkdir -p "$DEST"
sudo rsync -a --delete \
  --exclude '.git' \
  --exclude 'node_modules' \
  --chown=33:33 \
  "${SRC}/" "$DEST/"
sudo find "$DEST" -type d -exec chmod 755 {} +
sudo find "$DEST" -type f -exec chmod 644 {} +
echo "    $(sudo find "$DEST" -name '*.php' | wc -l) php files"

# Lint the deployed copy, using the same PHP that will run it. A parse error in
# an active plugin white-screens the site, so this gates activation.
echo "==> syntax check (php $(docker exec "$CONTAINER" php -r 'echo PHP_VERSION;'))"
fail=0
while IFS= read -r rel; do
  if ! docker exec "$CONTAINER" php -l "${IN_CONTAINER}/${rel}" >/dev/null 2>&1; then
    echo "    FAIL $rel" >&2
    docker exec "$CONTAINER" php -l "${IN_CONTAINER}/${rel}" 2>&1 | sed 's/^/      /' >&2 || true
    fail=1
  fi
done < <(cd "$SRC" && find . -name '*.php' | sed 's|^\./||' | sort)

if [ "$fail" -ne 0 ]; then
  echo "error: syntax errors in the deployed copy; NOT activating" >&2
  exit 1
fi
echo "    ok"

echo "==> plugin status"
docker exec "$CONTAINER" sh -c "cd /var/www/html && wp --allow-root plugin list --name=nvr-hs --fields=name,status,version --format=table"

if [ "${1:-}" = "--activate" ]; then
  echo "==> activate"
  docker exec "$CONTAINER" sh -c "cd /var/www/html && wp --allow-root plugin activate nvr-hs"

  echo "==> smoke test"
  docker exec "$CONTAINER" sh -c "cd /var/www/html && wp --allow-root nvr-hs test" || true
fi

echo "==> site check"
curl -s -o /dev/null -w "    home http=%{http_code} ttfb=%{time_starttransfer}s\n" --max-time 30 https://navira.run.biz.id/

echo "==> done"
