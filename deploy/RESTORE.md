# Restoring Ombú on a clean machine

How to bring production (SER5) back from nothing: new hardware, new disks,
or the same machine after a loss. Written 2026-11-17 from what the repo
holds; the lines marked **check** were not verified from the laptop (no ssh
from this session) and must be confirmed on SER5 once.

The one thing this document cannot restore from a machine that is gone is
`deploy/.env`: it is git-ignored and holds every secret. **Copy it off SER5
today** (password manager, or the laptop's `~/backups/ombu/`), and check its
copy opens and holds the keys below. Everything else is in git or in the
nightly backup.

## What lives where

| Thing | Where | Restore source |
|---|---|---|
| Code, config (`config/sync`), seeds, this doc | git, `github.com/quebracho-digital/ombu` | clone |
| Database | MariaDB 11.8, `db` volume | nightly backup + a dump before each deploy |
| Secrets | `deploy/.env` (git-ignored, mode 600) | off-host copy (**make it today**) |
| Meilisearch index | `meili` volume | rebuilt (`drush sapi-i`), no backup needed |
| Uploaded files (images, `ombu/screening` lists) | `web/sites/default/files` in the checkout | copy from SER5, or from the nightly backup |
| Access logs | `logs` volume, `access-*.log(.gz)` | optional: copy from SER5 |
| Cloudflare tunnel | Cloudflare dashboard + `cloudflared` on SER5 | dashboard (ingress → `http://192.168.1.66:8097`) |

## Before there is an incident (do once, on SER5)

1. **`deploy/.env` off the host.** `scp ser5:/data/docker/ombu/src/ombu/deploy/.env`
   to `~/backups/ombu/env-<date>/` (mode 600). It must hold, per
   `settings.prod.php` and the seeds: `MARIADB_DATABASE/USER/PASSWORD`,
   `OMBU_HASH_SALT`, `OMBU_HOST`, `OMBU_PORT`, `MEILI_MASTER_KEY`,
   `X402_GAS_KEY`, `OMBU_SELLER_ADDRESS`, `OPENROUTER_API_KEY`,
   `GROQ_API_KEY`, `FIREWORKS_API_KEY`, `OMBU_BREVO_SMTP_KEY`,
   `OMBU_FACTCHECK_API_AUTH` (and `OMBU_FACTCHECK_API_URL`),
   `CHAINALYSIS_API_KEY`. Without the hash salt, sessions and one-time
   links break on restore (a new one works, it just invalidates them).
2. **A fresh dump, now, not only before deploys:**
   `cd /data/docker/ombu/src/ombu/deploy && docker compose exec -T db \
   mariadb-dump -u$MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE \
   | gzip > ~/backups/ombu/manual-$(date +%F).sql.gz`
3. **A copy of `web/sites/default/files`** (sellers' images and the
   screening lists under `…/private/ombu/screening`): rsync it next to the
   dump. The screening lists are small and re-creatable; the images are not.
4. **Check** the nightly backup really covers Ombú: `grep -A8 '\[ombu\]'
   /etc/borgmatic/servicios.toml`, run one restore of its latest archive
   into `/tmp`, and open the `.sql.gz` inside. **Check** how cron runs
   (`crontab -l` on the host, or `docker compose exec web drush cron`) and
   write it down here.

## Restore, on a clean machine

Assumes Debian-ish host with Docker Compose, git, and the machine's LAN
address is `192.168.1.66` (SER5's; see step 7 if it changed).

1. **Code.**
   ```sh
   mkdir -p /data/docker/ombu/src && cd /data/docker/ombu/src
   git clone git@github.com:quebracho-digital/ombu.git ombu && cd ombu
   git checkout <the commit production was on>   # or main
   ```
2. **Secrets.** Restore `deploy/.env` from the off-host copy: `chmod 600
   deploy/.env`. Check `OMBU_HOST=ombu.quebracho.digital` and the stack
   name (`OMBU_STACK=ombu`, `OMBU_PORT=8097`) are as on SER5.
3. **Files.** Restore the backup of `web/sites/default/files` into the
   checkout (or start empty: the site runs, sellers' images and screening
   lists are missing until this is done).
4. **Up, without data.** `cd deploy && docker compose up -d --build`
   (builds the PHP 8.3 + Apache image, starts db and meilisearch empty).
5. **Database in.** Empty volume first, then load, then the site:
   ```sh
   docker compose exec -T db sh -c \
     'mariadb -u"'"$MARIADB_USER"'" -p"'"$MARIADB_PASSWORD"'" "$MARIADB_DATABASE"' \
     < /path/to/dump.sql.gz   # the .gz gunzipped, or mariadb-dump's | gunzip
   docker compose exec -T web composer install --no-dev
   docker compose exec -T web drush cr
   docker compose exec -T web drush deploy    # config/sync import + updates
   ```
   `drush deploy` also re-imports `config/sync`, so the site's settings are
   the repo's, not the dump's.
6. **Search again.** The Meilisearch volume is new and empty; index both:
   ```sh
   docker compose exec -T web drush search-api:reset-tracker
   docker compose exec -T web drush search-api:index
   ```
   About a minute for ~2.8k listings. Until it finishes, `/market` is empty.
   **Check** the server answers: `docker compose exec -T web drush
   php:script - <<<'var_dump(\Drupal\search_api\Entity\Server::load("meilisearch")->backend()->ping());'`
7. **The door from the internet.** In Cloudflare's dashboard, the tunnel's
   ingress for `ombu.quebracho.digital` points at `http://192.168.1.66:8097`
   (SER5's own `cloudflared`; the port is bound to the LAN address only, per
   `deploy/compose.yaml`). If the new machine has another address, change
   the ingress **and** `deploy/settings.prod.php`'s
   `reverse_proxy_addresses` + `trusted_host_patterns` (commit the change,
   do not edit on the server only).
8. **Cron.** Re-create whatever step "check" found above (a host crontab
   line like `cd /data/docker/ombu/src/ombu/deploy && docker compose exec
   -T web drush cron` every few minutes, or SER5's own service). Without
   it: no imports, no sweeps, no log pruning, no model refreshes.
9. **Logs** (optional): copy the old `access-*.log(.gz)` into the new
   `logs` volume; `logs-prune` takes over from there.

## Check afterwards, in order

```sh
curl -sI https://ombu.quebracho.digital | head -1            # 200
curl -s https://ombu.quebracho.digital/market.json | head -c 300   # listings with facets
curl -s https://ombu.quebracho.digital/mcp -X POST …          # initialize answers
curl -sI https://ombu.quebracho.digital/x402/commerce_product/<a-paid-id>/full  # 402 with PAYMENT-REQUIRED
```

And on the machine:

- `docker compose exec -T web drush ombu:stop status` → running (a restored
  site must not come back stopped by accident; if the dump was taken while
  stopped, `drush ombu:stop off` says why it was stopped before resuming).
- `docker compose exec -T web drush php:script ../scripts/check-similar.php`
  → the similar block answers.
- The gas wallet still has USDC on Arc (`X402_GAS_KEY`): a sale settles
  from it; check its balance on explorer.arc.io before announcing anything.
- One real purchase of the cheapest seller (ping, 0.005) with
  `scripts/buy-ours.sh` against the restored host, then
  `drush pml`-style: the sale shows at `/market/sales` with its tx.
- `docker compose logs web --since 10m`: no errors.

## What this does not cover

- The buyer's and sellers' keys kept off the site (`scripts/.env.mainnet`
  on Leo's laptop, the cold wallet): they never lived on SER5.
- Anything already on the blockchain: sales are public and permanent; the
  payment log inside the dump tells Ombú what it sold.
- Cloudflare's own settings (WAF, DNS): in Cloudflare's dashboard, not in
  this repo. Note the WAF skip rule that lets agents through: agents cannot
  pass a managed challenge (ROADMAP, decisions of 2026-10-13).
