# Staging deploy

Target: `https://api-staging.{{APP_DOMAIN}}` on the CloudPanel VPS (Nginx + PHP-FPM),
database `{{DB_HOST}}`.

> `{{APP_DOMAIN}}` and `{{DB_HOST}}` were placeholders in the Phase 0A brief and
> are still unknown. Substitute them below. Nothing here assumes their values,
> except where a step is marked **Supabase only**.

## What is automated, and what is not

| Automated (every deploy) | Manual (once, below) |
|---|---|
| New release folder, `composer install --no-dev` | DNS, CloudPanel site, TLS |
| `config` / `route` / `event` / `view` cache (`artisan optimize`) | Database role and schema |
| `php artisan migrate --force`, **before** the switch | `shared/.env` on the server |
| Atomic switch of the `current` symlink | SSH keys (CI → server, server → GitHub) |
| `artisan reload` (queue workers restart after their current job) | Supervisor program for the queue worker |
| Post-deploy `GET /api/v1/health` (if `DEPLOY_HEALTH_URL` is set) | Scheduler cron entry |
| Keeps the last 5 releases for `dep rollback` | GitHub secrets |

Deploys run through [Deployer](https://deployer.org) (`deploy.php`), from the
**Deploy staging** GitHub Action or from a Linux/macOS shell. Deployer's SSH
multiplexing does not work with Windows OpenSSH, so deploy from CI or WSL, not
from PowerShell.

Layout on the server (`DEPLOY_PATH` below):

```
/home/<site-user>/htdocs/api-staging.{{APP_DOMAIN}}/
├── current -> releases/7      # Nginx root is current/public
├── releases/3 … releases/7
└── shared/
    ├── .env                   # created by you, step 4
    └── storage/               # logs, cache; survives deploys
```

---

## 1. Database

### 1a. Check the server version

```sql
select version();
```

Set the same **major** version in `docker-compose.yml` (`POSTGRES_VERSION`) and
in `.github/workflows/ci.yml` (`services.postgres.image`) so local and CI
match. Both default to 17.

### 1b. Create a role and schema for the API

The API keeps every table in its own schema (`DB_SCHEMA`, default
`torquelane`), never in `public`. On a shared Supabase project, `public`
belongs to the legacy frontend's `pms_*` tables and to the unrelated app.

Run as an admin (`postgres` on Supabase):

```sql
create role torquelane_api login password '<generate a long random password>';
create schema torquelane authorization torquelane_api;
-- Let the role find its own schema first.
alter role torquelane_api set search_path = torquelane;
```

`php artisan migrate` would create the schema itself
(`App\Database\EnsureSchemaExists`), but only if the role has `CREATE` on the
database. Creating it here, owned by the API's role, means the role needs no
database-wide privileges at all.

### 1c. Supabase only: how to connect

- Connect **directly** (`db.<project-ref>.supabase.co:5432`) or through the
  **session-mode** pooler (`aws-0-<region>.pooler.supabase.com:5432`). **Never
  the transaction-mode pooler on port 6543.** Laravel uses prepared statements
  and holds a connection across a transaction; transaction pooling breaks both.
- The direct host is IPv6-only unless the project has the IPv4 add-on. If the
  VPS has no IPv6 route, use the session pooler.
- Through the pooler, the username carries the project ref:
  `torquelane_api.<project-ref>`.
- Use `DB_SSLMODE=require`.
- In **Project Settings → API → Exposed schemas**, do **not** add `torquelane`.
  The `2026_10_08_100200_revoke_supabase_api_roles` migration also revokes
  every privilege the `anon` and `authenticated` roles could have on the schema,
  including `USAGE`, so even an accidental exposure reads nothing. It runs with
  the other migrations; there is nothing to do by hand.

### 1d. Plain Postgres

Same role and schema as 1b. The Supabase revoke migration is a no-op when the
`anon` / `authenticated` roles don't exist. Use `DB_SSLMODE=require` unless the
database is on the same host.

---

## 2. CloudPanel site

1. **DNS**: an `A` (and `AAAA`, if the VPS has IPv6) record for
   `api-staging.{{APP_DOMAIN}}` pointing at the VPS.
2. **Add Site → Create a PHP Site**
   - Application: *Generic* (or *Laravel*)
   - Domain: `api-staging.{{APP_DOMAIN}}`
   - PHP version: **8.4**
   - Site user: e.g. `torquelane-api-staging`. This user owns the files and runs
     PHP-FPM, deploys, the worker and the cron.
3. **Settings → Root Directory**: `current/public`
   (relative to `htdocs/api-staging.{{APP_DOMAIN}}`).
4. **SSL/TLS → New Let's Encrypt Certificate** once DNS resolves.
5. **Vhost**: in the `location ~ \.php$` block, make PHP-FPM resolve the
   symlink. Otherwise OPcache keeps serving the previous release's files after
   `current` switches:

   ```nginx
   fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
   fastcgi_param DOCUMENT_ROOT $realpath_root;
   ```

   Replace the existing `SCRIPT_FILENAME` line if one uses `$document_root`.
6. **PHP extensions**: as the site user, `php8.4 -m` must list `pdo_pgsql`,
   `pgsql`, `mbstring`, `intl`, `bcmath`, `openssl`, `curl`, `fileinfo`.
   CloudPanel's PHP builds include them; install any that are missing.

---

## 3. SSH keys

Two key pairs, both one-time:

**CI → server** (the Deploy staging action logs in as the site user):

```bash
ssh-keygen -t ed25519 -C "torquelane-api deploy" -f torquelane_deploy -N ""
```

- `torquelane_deploy.pub` → CloudPanel **Site → SSH/FTP → SSH Keys** (or the
  site user's `~/.ssh/authorized_keys`).
- `torquelane_deploy` (private) → GitHub secret `DEPLOY_SSH_KEY`.
- `ssh-keyscan -t ed25519 <vps-host>` output → GitHub secret `DEPLOY_KNOWN_HOSTS`.

**Server → GitHub** (the server clones the repo on each deploy). As the site
user on the VPS:

```bash
ssh-keygen -t ed25519 -C "api-staging read-only" -f ~/.ssh/id_ed25519 -N ""
ssh-keyscan -t ed25519 github.com >> ~/.ssh/known_hosts
cat ~/.ssh/id_ed25519.pub
```

Add that public key to the repository under **Settings → Deploy keys**,
read-only.

---

## 4. `shared/.env`

Create it **before the first deploy**. `artisan optimize` caches whatever is in
it, and `migrate` skips when it is missing:

```bash
mkdir -p DEPLOY_PATH/shared
nano DEPLOY_PATH/shared/.env
chmod 600 DEPLOY_PATH/shared/.env
```

Start from `.env.example` and change these:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://api-staging.{{APP_DOMAIN}}
APP_KEY=            # generate locally: php artisan key:generate --show

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info

DB_HOST={{DB_HOST}}
DB_PORT=5432
DB_DATABASE=postgres          # Supabase's database is always `postgres`
DB_USERNAME=torquelane_api    # torquelane_api.<project-ref> via the Supabase pooler
DB_PASSWORD=<from step 1b>
DB_SCHEMA=torquelane
DB_SSLMODE=require

MAIL_MAILER=log               # until a real SMTP provider is chosen
```

The `POSTGRES_VERSION` / `FORWARD_*` variables are for docker-compose only;
leave them out. Never commit this file. After changing it on a live server,
redeploy (or run `php artisan config:cache` in `current`); the config is cached.

---

## 5. Queue worker (Supervisor)

CloudPanel does not manage Supervisor. As root:

```bash
apt-get install -y supervisor
cp docs/deploy/supervisor-torquelane-worker.conf /etc/supervisor/conf.d/torquelane-api-staging-worker.conf
# edit SITE_USER and DEPLOY_PATH in that file
supervisorctl reread && supervisorctl update
supervisorctl status torquelane-api-staging-worker:*
```

The file is in this repo at `docs/deploy/supervisor-torquelane-worker.conf`.
Deploys restart the worker gracefully (`artisan reload` → `queue:restart`).
Supervisor brings it back up on the new release.

## 6. Scheduler (cron)

CloudPanel → **Site → Cron Jobs → Add Cron Job**, as the site user:

```
* * * * *   /usr/bin/php8.4 DEPLOY_PATH/current/artisan schedule:run >> /dev/null 2>&1
```

The schedule (`routes/console.php`): a queue heartbeat every minute, which is
what `/api/v1/health` reports, and `model:prune` daily at 03:00 Manila
(expired Idempotency-Keys).

---

## 7. GitHub

Repository **Settings → Environments → New environment `staging`**, then:

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | VPS hostname or IP |
| `DEPLOY_USER` | the CloudPanel site user |
| `DEPLOY_PATH` | `/home/<site-user>/htdocs/api-staging.{{APP_DOMAIN}}` |
| `DEPLOY_REPOSITORY` | `git@github.com:<owner>/<repo>.git` |
| `DEPLOY_SSH_KEY` | private key from step 3 |
| `DEPLOY_KNOWN_HOSTS` | `ssh-keyscan` line from step 3 |

| Variable | Value |
|---|---|
| `DEPLOY_HEALTH_URL` | `https://api-staging.{{APP_DOMAIN}}/api/v1/health` |

Then **Actions → Deploy staging → Run workflow**.

From WSL or a Linux/macOS shell instead:

```bash
export DEPLOY_HOST=… DEPLOY_USER=… DEPLOY_PATH=… DEPLOY_REPOSITORY=…
export DEPLOY_HEALTH_URL=https://api-staging.{{APP_DOMAIN}}/api/v1/health
vendor/bin/dep deploy stage=staging
```

---

## 8. Verify

```bash
curl -s https://api-staging.{{APP_DOMAIN}}/api/v1/health | jq
```

- Right after the first deploy, expect `"status": "degraded"` with
  `checks.queue.status: "down"`: no heartbeat has run yet.
- Within two minutes (cron dispatches, worker runs), expect `"status": "ok"`.
  If it stays `degraded`, the cron entry (step 6) or the worker (step 5) isn't
  running. `last_heartbeat_at` tells you when it last worked.
- A 503 with `error.code: "server_error"` means the API cannot reach the
  database; check `shared/.env` and `shared/storage/logs/`.

Every response carries an `X-Request-Id` header. Quote it when reading logs;
each log line carries the same `request_id`.

## Rollback

```bash
vendor/bin/dep rollback stage=staging
```

This switches `current` back one release. It does **not** roll migrations back,
and per R10 a migration that ran on staging is never edited. Migrations run
before the switch, so they must keep the previous release working
(expand/contract). The rollback then needs no schema change.
