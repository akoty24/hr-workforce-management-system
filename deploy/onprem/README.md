# On-premises installation guide

For IT teams installing HR Workforce on their own server. One installation serves one company.

## Requirements

- Linux server with Docker Engine 24+ and the Docker Compose plugin
- 2 GB RAM, 10 GB disk (more for uploaded files and backups)
- Optional: an existing **MySQL 8.4 LTS** server instead of the bundled one. MariaDB is not supported.

## Install

```bash
cd deploy/onprem
cp .env.onprem.example .env
# 1. Edit .env: APP_URL, DB_PASSWORD, MYSQL_ROOT_PASSWORD, MAIL_*.
# 2. Generate the application key and paste it into APP_KEY:
docker compose run --rm --no-deps app php artisan key:generate --show

docker compose up -d                       # builds the image, starts MySQL + app + worker + scheduler
docker compose exec app php artisan tenant:install   # creates the company and first administrator
docker compose exec app php artisan system:check     # everything should say OK
```

Open `APP_URL` and sign in with the administrator account printed by `tenant:install`.

**HTTPS:** put the system behind your reverse proxy (nginx, IIS, a load balancer) and set `TRUSTED_PROXIES` and `SESSION_SECURE_COOKIE=true`.

## Using an existing MySQL server

Remove the `mysql` service and the `depends_on` blocks from `docker-compose.yml`, then set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env`. Create the database with:

```sql
CREATE DATABASE hr_workforce CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hr_workforce'@'%' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON hr_workforce.* TO 'hr_workforce'@'%';
```

## Backups

Back up **both** the database and uploaded files, daily:

```bash
docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines "$MYSQL_DATABASE"' > backup-$(date +%F).sql
docker run --rm -v hr-workforce_app-storage:/data -v "$PWD":/backup alpine tar czf /backup/files-$(date +%F).tar.gz -C /data .
```

Restore: `docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot "$MYSQL_DATABASE"' < backup.sql`

## Upgrading

1. **Take a backup** (MySQL schema changes are not transactional).
2. Get the new release (new code or image tag).
3. `docker compose up -d --build` — the `app` service runs pending migrations on start; if a migration fails, the app does not start and the error is in `docker compose logs app`.
4. `docker compose exec app php artisan system:check`

Releases can be skipped: migrations apply in order from any earlier version.

## Troubleshooting

| Symptom | Check |
|---|---|
| Page shows "Workspace not found" | `php artisan tenant:install` not run yet |
| App container restarts | `docker compose logs app` (missing `APP_KEY`, wrong DB password, failed migration) |
| Health | `curl http://localhost:8080/health/ready` → `{"status":"ok"}` |
