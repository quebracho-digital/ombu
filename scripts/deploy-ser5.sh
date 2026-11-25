#!/usr/bin/env bash
# Deploys Ombú to SER5 the one way Leo allows: a commit that is already on
# GitHub, brought with `git pull`. Nothing is copied by scp, rsync or docker cp.
#
# Run only after Leo says "deploya":  scripts/deploy-ser5.sh
#
# Order (Leo, 2026-11-25): commit - push - ssh - backup - cex - git pull -
# composer install - updb/cim - cr. Any problem stops it; up to and including
# the git pull nothing has changed, so the maintenance mode goes back as it was
# and Claude tells Leo before anything else is touched.
set -euo pipefail

HOST=${SER5_HOST:-quebracho@192.168.1.66}
SRC=/data/docker/ombu/src/ombu
STAMP=$(date +%Y%m%d-%H%M)
say() { printf '\n== %s\n' "$*"; }
MAINT_WAS=
restore() {
  if [ -n "$MAINT_WAS" ] && [ "$MAINT_WAS" != "$MAINT_NOW" ]; then
    drush "state:set system.maintenance_mode $MAINT_WAS -y" >/dev/null 2>&1 || true
    echo "Stopped before the pull finished: maintenance mode back to $MAINT_WAS, nothing was changed." >&2
  fi
}
MAINT_NOW=
remote() { ssh "$HOST" "cd $SRC/deploy && $*"; }
drush() { remote "docker compose exec -T web drush $*"; }

say "1. Local: the commit to deploy is committed and on GitHub"
cd "$(dirname "$0")/.."
git fetch -q origin main
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Uncommitted changes in tracked files: commit first." >&2; exit 1
fi
if [ "$(git rev-parse HEAD)" != "$(git rev-parse origin/main)" ]; then
  echo "HEAD is not origin/main: push (with Leo's OK) or pull first." >&2; exit 1
fi
NEW=$(git rev-parse --short HEAD)

say "2. SER5: the checkout is clean, and what will come"
remote "git fetch -q origin main"
DIRTY=$(remote "git status --porcelain --untracked-files=no")
if [ -n "$DIRTY" ]; then
  echo "SER5 has local changes in tracked files; git pull could conflict:" >&2
  echo "$DIRTY" >&2; exit 2
fi
OLD=$(remote "git rev-parse --short HEAD")
echo "SER5 $OLD -> $NEW"
remote "git log --oneline HEAD..origin/main" | head -40
if ! remote "git merge-base --is-ancestor HEAD origin/main"; then
  echo "SER5 is not an ancestor of origin/main (diverged): no fast-forward." >&2; exit 2
fi

say "3. Backup: database and files"
remote "mkdir -p /data/docker/ombu/backups && docker compose exec -T db sh -c 'mariadb-dump -u\$MARIADB_USER -p\$MARIADB_PASSWORD \$MARIADB_DATABASE' | gzip > /data/docker/ombu/pre-$NEW-$STAMP.sql.gz && gzip -t /data/docker/ombu/pre-$NEW-$STAMP.sql.gz && tar -czf /data/docker/ombu/pre-$NEW-$STAMP-files.tgz -C $SRC web/sites/default/files"
remote "ls -lh /data/docker/ombu/pre-$NEW-$STAMP*"

say "4. Config on SER5 against config/sync (drift) before pulling"
MAINT_WAS=$(drush "state:get system.maintenance_mode" | tr -dc '01'); MAINT_WAS=${MAINT_WAS:-0}
trap restore ERR
MAINT_NOW=1
drush "state:set system.maintenance_mode 1 -y"
drush "config:export --destination=/tmp/prod-cex-$STAMP -y" | tail -3
remote "docker compose exec -T web diff -rq /tmp/prod-cex-$STAMP ../config/sync | head -30 || true"
remote "docker compose exec -T web rm -rf /tmp/prod-cex-$STAMP"

say "5. git pull --ff-only"
remote "git pull --ff-only"
trap - ERR
echo "Pulled: from here a failure leaves the site in maintenance mode."

say "6. composer install (checks the git packages really moved)"
remote "docker compose exec -T -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0='*' web composer install --no-dev --no-interaction"

if [ -n "$(remote "git diff --name-only $OLD HEAD -- Dockerfile deploy/Dockerfile deploy/*.conf deploy/compose.yaml")" ]; then
  say "6b. The image or compose changed: rebuild the web container"
  remote "docker compose up -d --build web"
fi

say "7. drush deploy = updb, cim, cr, deploy hooks"
drush "deploy -y"
drush "state:set system.maintenance_mode 0 -y"
drush "cr"

say "8. Checks"
HOST_URL=${OMBU_URL:-https://ombu.quebracho.digital}
for p in "" market.json llms.txt; do
  printf '%-14s ' "/$p"; curl -s -o /dev/null -w '%{http_code}\n' "$HOST_URL/$p"
done
printf '%-14s ' "install.php"; curl -s -o /dev/null -w '%{http_code} (403 expected)\n' "$HOST_URL/core/install.php"
drush "updbst" | tail -2
echo "Deployed $NEW. Seeds, the live purchase and the blog post are separate steps."
