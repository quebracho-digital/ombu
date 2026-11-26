# Security at rest: what Drupal does, what it does not, and where Ombú stands

Recorded 2026-11-26, from Leo's question: "¿no deberíamos encriptar el contenido
de la base de datos? especialmente los datos sensibles de clientes o para el
proyecto de crm? supongo que drupal ya lo tiene resuelto".

**Nothing below is implemented.** This is the finding and the proposal, for Leo
to decide. Every measurement was read-only (`lsblk`, `ls`, `df`, `drush sqlq`,
`systemctl cat`, `git grep`) on 2026-11-26; no key was rotated, no file was
moved and no package was installed.

## The short answer

Drupal does not encrypt the content of the database, and encrypting it as a
blanket measure is not what fixes the real exposure. Core solves the doors
around the data (password hashing, signed sessions, one-time tokens, secrets
kept out of configuration); it does not solve the data itself. What that data
needs, in this order, is: copies protected, disk protected, access narrowed,
staff authenticated with a second factor. Field-level encryption comes last and
only for the few fields that justify it.

## What core and this site already do

| | Evidence |
|---|---|
| Passwords are hashed, never stored | `PhpPassword` over `password_hash`; today `$2y$10$…` (bcrypt, cost 10, PHP 8.3.37). `argon2i` and `argon2id` are available. The algorithm is a container parameter (`password.algorithm`, `password.options` in `core.services.yml`), overridable from `sites/default/services.yml`. |
| Secrets do not live in the database or in git | `key` 8.x-1.23 is enabled and **all 15 keys use `key_provider: env`**: `config/sync/key.key.gas_wallet.yml` names `X402_GAS_KEY` and nothing else. `grep` over `config/sync` finds no key material. `deploy/.env` is mode `600`. |
| The database is not reachable from the network | `deploy/compose.yaml`: `db` and `meili` publish no port; only the web container binds `192.168.1.66:8097`. |
| Sessions and forms are signed, CSRF tokens are per form | core |
| Nightly backups of the node are encrypted | `ser5-backup.timer` runs `/usr/local/sbin/ser5-backup-run.sh`: dumps to `/data/backups/dumps`, then `restic backup` of `/data/docker`, `/etc/...` and that directory to `/mnt/backups/quebrachos/restic` and to an offsite repo. Restic encrypts its repository; the passphrase is `/root/.config/restic/pass`, the weekly `ser5-backup-check.timer` verifies integrity, and failures report to Gatus. |

## What Drupal does not do

- **No field or table encryption in core.** `node`, `users_field_data`,
  `commerce_order`, `profile` and CRM notes are plain text in MariaDB.
- **Contrib exists, with a cost.** `encrypt` + `real_aes` (Defuse
  php-encryption) over `key` is the solid base; `field_encrypt` is the
  field-level wrapper and is still beta. What it costs:
  - the field stops being queryable: no filters, no sorts, no `LIKE`, no
    facets, no uniqueness check;
  - **Search API and Meilisearch index the plain text anyway** unless the field
    is excluded from the index, so the leak moves rather than closes;
  - rotation needs a key version per row, otherwise it is a trap two years out;
  - migrations, `drush` scripts and views assume text.
- **It does not stop the attacker that matters most.** The key lives on the
  same server as the application: a SQL injection, a compromised admin or an
  RCE reads the data with the key. Field encryption protects dumps, replicas,
  stolen disks and curious DBAs, and nothing else. That is worth having, but it
  is not "the database is encrypted, we are done".

## Where the data stands today (measured)

| # | Severity | Finding | Evidence |
|---|---|---|---|
| 1 | Medium | The whole node's database dumps are world-readable plain text, and they include other services (OpenEMR health data, Authentik identity data, a WhatsApp bridge), not only Ombú | `/data/backups/dumps/ombu-mariadb.sql`, 419 MB, `root:root`, mode `644`, rewritten every night at 03:08 |
| 2 | Medium | The deploy keeps its own pre-deploy dumps inside the application directory, world-readable, and they accumulate: 17 files, 912 MB | `/data/docker/ombu/pre-*.sql.gz` and `cutover.sql.gz`, mode `664`; written by `scripts/deploy-ser5.sh` step 3 |
| 3 | Medium | `/data` (database volume, dumps, repositories) is not encrypted at rest, and neither are the backup disks | `lsblk -f`: `nvme0n1p4 ext4 /data`, `sda1 ext4 /mnt/backups`, `sdb2 exfat /mnt/backup-ext`, no LUKS anywhere |
| 4 | Low | Passwords use bcrypt cost 10, the PHP 8.3 default | `password_hash('x', PASSWORD_DEFAULT)` → `$2y$10$…` |
| 5 | Low | No second factor for staff | `drush pm:list` shows no `tfa`; no `seckit` either |
| 6 | Info | Personal data volume is small: the catalogue, not customers, is the bulk | production 7 users (1 with a mail), 1 order, 1 order item, 0 profiles; dev 5 / 1 / 1 / 0 |

Findings 1 and 2 matter because the files are readable by any local process,
any container that mounts `/data`, and any copy of the directory (the restic snapshots carry them too, encrypted there but in the clear on the way in). Finding 3 is
what makes 1 and 2 worse: a disk pulled from the server has everything in the
clear.

## What I recommend, in this order

1. **Protect the dumps (half a day).** Mode `600` root-only for
   `/data/backups/dumps/*` and `640` for the deploy's, a `drush sql:dump | age
   -r …` (or `gpg`) in `deploy-ser5.sh` and in `ser5-backup-run.sh`, recipient
   key kept off the server, and a retention rule (today nothing prunes the 17
   pre-deploy files). Do not leave dumps inside the checkout: `deploy-ser5.sh`
   should write them under `/data/backups/dumps/ombu/` and delete them after the
   deploy succeeds.
2. **argon2id (minutes).** `parameters: { password.algorithm: argon2id,
   password.options: { memory_cost: 65536, time_cost: 4, threads: 2 } }` in
   `sites/default/services.yml`. Existing hashes keep working and are upgraded
   at the next login.
3. **Encryption at rest for `/data` (an hour of downtime, plus a restore
   test).** LUKS on `nvme0n1p4`, or MariaDB's InnoDB encryption on 11.8 with the
   keyring file outside the volume. Without one of the two, findings 1 and 2
   stay alive for anything with physical or filesystem access.
4. **Second factor and a hardening pass for staff (half a day).** `tfa` for
   the roles that can read orders and profiles, `seckit`, an audit trail
   (`audit_log` or the watchdog with a retention rule), least privilege on the
   database user.
5. **The CRM's notes, and only those (see below).**
6. **Never card data**, not even encrypted: tokenisation at the gateway.

Legal note, since Leo asked in general terms: Ley 25.326 (art. 9) and GDPR
(art. 32) do not require "an encrypted database". They require adequate
technical measures; encryption at rest, protected copies, named access,
minimisation and retention are the ones an auditor asks for first. With 7 users
and 1 order, Ombú's own risk is small: the catalogue is public data and the
valuable secrets (wallets, AI keys) are already outside the database.

## The CRM plan (`crm_activity`)

`details` in `crm_activity` is the field that concentrates personal data:
what was said on a call, in a meeting, in a mail. The plan, for when Leo says
go:

- A small service, `CrmCipher`, over **libsodium** (`sodium_crypto_secretbox`),
  which ships with PHP, so no new dependency and no beta module. Envelope
  encryption: a data key per row, wrapped by a master key from `key` with
  `key_provider: env`.
- A `key_version` column beside the ciphertext so a master key can be rotated
  without a downtime migration, and a `drush crm:cipher-reencrypt` command.
- `details` excluded from every Search API index and from Views filters: it
  becomes write-and-read-only. If a lookup inside the text is ever needed, an
  HMAC blind index on the exact value, never a `LIKE`.
- Everything else stays in clear text because it is queried: type, date,
  status, duration, participants, regarding.
- Not encrypted on purpose: contact names and mails (they are the index of the
  CRM; protect them with access control, not with ciphertext).

The alternative, if the CRM ever holds health or legal data at scale, is to
run it on its own database with encryption at rest and drop field-level
encryption altogether: simpler, and it protects everything instead of two
columns.

## How to check that a fix worked

```sh
# dumps are root-only and encrypted
ls -l /data/backups/dumps/ | head
file /data/backups/dumps/ombu-mariadb.sql.age

# no dump left in the checkout
ls /data/docker/ombu/*.sql.gz 2>/dev/null | wc -l   # 0

# the data volume sits on LUKS
lsblk -f | grep -E "crypt|/data"

# new passwords are argon2id
ddev drush php:eval 'echo \Drupal::service("password")->hash("x");'   # $argon2id$...
```
