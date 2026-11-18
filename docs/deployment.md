# Deployment

One Docker image ([deploy/docker/Dockerfile](../deploy/docker/Dockerfile)) runs everywhere. Behaviour comes only from environment variables.

| Target | How |
|---|---|
| On-premises | [deploy/onprem/README.md](../deploy/onprem/README.md) (Docker Compose bundle with MySQL 8.4) |
| Railway | below |
| Any other container platform | same image + the variables below + a MySQL 8.4 database |

> **Status:** the image, Compose bundle, Railway config and CI pipeline are written but have **not yet been run** (no Docker on the development machine). The first CI run on GitHub and the first Railway deploy are their real test, and also the first test against MySQL 8.4.

## Process roles

| `CONTAINER_ROLE` | Runs | Notes |
|---|---|---|
| `web` | FrankenPHP on `$PORT` (default 8080) | Health check: `/up` |
| `worker` | `queue:work` | Background jobs |
| `scheduler` | `schedule:work` | Scheduled tasks |
| `all` | all three in one container | Small hosts / Railway free plan only |

Set `RUN_MIGRATIONS=true` on **exactly one** service (the `web` or `all` one). Migrations run before the app starts; a failure stops the container so a half-migrated release never serves traffic. (`migrate --isolated` is not used: its lock needs the cache table, which does not exist before the first migration.)

## Environment variables

| Variable | Required | Example / default | Notes |
|---|---|---|---|
| `APP_KEY` | yes | `base64:…` | `php artisan key:generate --show`; keep it stable (encrypts sessions/cookies) |
| `APP_URL` | yes | `https://hr.example.com` | |
| `APP_ENV` / `APP_DEBUG` | | `production` / `false` | set in the image |
| `DB_URL` **or** `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | yes | `mysql://user:pass@host:3306/db` | MySQL 8.4 LTS |
| `CONTAINER_ROLE` | | `web` | see above |
| `RUN_MIGRATIONS` | | `false` | `true` on one service |
| `TENANCY_RESOLVER` | | `single` | `subdomain` for SaaS with `TENANCY_BASE_DOMAIN` |
| `TENANCY_BASE_DOMAIN` | for `subdomain` | `hrworkforce.com` | |
| `TRUSTED_PROXIES` | behind TLS proxy | `*` | needed on Railway / most PaaS |
| `SESSION_SECURE_COOKIE` | with HTTPS | `true` | |
| `FILESYSTEM_DISK` | | `local` | `s3` + `AWS_*` (+ `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT=true` for MinIO/R2) |
| `MAIL_*` | for email | SMTP | |
| `HR_WEEKEND_DAYS` | | `5,6` | ISO weekdays excluded from leave |

## Railway

1. Create a project and add a **MySQL** database service.
2. Add a service from this GitHub repository. Railway picks up `railway.toml` (Dockerfile build, `/up` health check).
3. Variables on the app service:
   ```
   APP_KEY=base64:...            # from: php artisan key:generate --show
   APP_URL=https://<your-app>.up.railway.app
   DB_URL=${{MySQL.MYSQL_URL}}   # reference variable; check the name in the MySQL service's Variables tab
   CONTAINER_ROLE=all            # free plan: one container; later split into web/worker/scheduler
   RUN_MIGRATIONS=true
   TRUSTED_PROXIES=*
   SESSION_SECURE_COOKIE=true
   TENANCY_RESOLVER=single
   ```
4. Generate a public domain for the service, then deploy.
5. Create the company and first admin from the service shell (Railway CLI: `railway ssh`, or the dashboard's shell):
   ```
   php artisan tenant:install
   php artisan system:check
   ```
6. Uploaded files: the container filesystem is wiped on each deploy. Attach a Railway volume at `/app/storage/app`, or use an S3-compatible bucket (`FILESYSTEM_DISK=s3`).

Free-plan limits (RAM, credits, sleeping services) change over time; check Railway's current pricing. A sleeping service also pauses the queue worker.

**Scaling up:** run the same image as three services (`CONTAINER_ROLE=web` with `RUN_MIGRATIONS=true`, `worker`, `scheduler`), all with the same variables.

## SaaS with subdomains

Attach a wildcard domain (`*.hrworkforce.com`) and set `TENANCY_RESOLVER=subdomain`, `TENANCY_BASE_DOMAIN=hrworkforce.com`. Each company is reached at `{slug}.hrworkforce.com`; create companies with `php artisan tenant:install` (with `TENANCY_SINGLE_TENANT` unset, the single-tenant guard does not apply in subdomain mode).

## CI

[.github/workflows/ci.yml](../.github/workflows/ci.yml): backend tests on a **MySQL 8.4** service, Pint style check, frontend lint + build, Docker image build.
