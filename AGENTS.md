# AGENTS.md

PlayTube is a self-hosted PHP video CMS (MySQLi + Apache/Nginx). Nothing here restates
the manifests; these are the setup facts and gotchas of the Base44 sandbox.

## Running it

```bash
docker compose -f docker-compose.base44.yml up -d --build   # db, db-setup (one-shot), web
```

- `web` is `php:7.4-apache` serving the bind-mounted checkout at `/var/www/html` through
  the repo's own `.htaccess` rewrite rules. There is no build step and no asset pipeline:
  PHP/CSS/JS edits are live on the next request. No dev-server restart is needed for code
  changes; restart `web` only after editing `docker/base44/entrypoint.sh` or the compose file.
- `db` is MySQL 5.7 (`base44playtube` / `base44playtube`, root password `base44root`).
- `db-setup` imports `playtube.sql` once and seeds the admin account, then exits.

Admin login: `admin` / `PlayTube!Admin1` (stored as legacy SHA1; the login flow re-hashes
it with `password_hash` on first successful sign-in).

## Non-obvious things

- **The bundled installer is unusable.** `/install` calls a remote Envato endpoint to
  validate a purchase code, so the database is provisioned directly by
  `docker/base44/db-setup.sh` + `docker/base44/db-setup.sql` instead. Never run the wizard.
- **`config.php` is gitignored and generated at container start** by
  `docker/base44/entrypoint.sh` from env vars (`DB_HOST`, `DB_USER`, `DB_PASSWORD`,
  `DB_NAME`, and `SITE_URL` or `PUBLIC_HOST_SUFFIX`). Delete it inside the container to
  regenerate. `$auto_redirect` is forced to `false` because the preview proxy terminates
  TLS, and the app's HTTP→HTTPS check only reads `$_SERVER['HTTPS']`.
- **`$site_url` must be the public preview URL**, not the internal host: the theme builds
  every CSS/JS/media link from it. Compose derives it from `BASE44_PUBLIC_HOST_SUFFIX`.
- **`nodejs/config.json` must exist and be readable** — `assets/includes/app_start.php`
  exits with an error without it. The optional Socket.IO service (`nodejs/`) is *not*
  started; the app falls back to AJAX polling for messages/notifications.
- **The image pins PHP 7.4 on purpose** (the codebase's era: `hash`, `sha1` logins,
  MysqliDb). Debian bullseye is end-of-life, so the Dockerfile points apt at
  `archive.debian.org`; `mbstring` and `curl` come from the base image, and only
  `mysqli gd zip calendar exif` are compiled in.
- **Video transcoding is disabled** (`ffmpeg_system = off` in the `config` table). The
  bundled `assets/libs/ffmpeg/ffmpeg` binary is not executable and `shell_exec` requires
  it to be writable (777) — enable the setting in the admin panel if conversion is needed.
- Uploads are written to `upload/` and `assets/import/`; the entrypoint chowns them to
  `www-data` because the bind mount is root-owned. It also `chmod a+rx` the document root
  so Apache can read `.htaccess`.
- **`upload/photos/YYYY/MM` must stay writable by `www-data`.** Avatars, cover images and
  video thumbnails are written into the current month directory by PHP. If that directory
  ends up owned by `root` (e.g. a file was copied in from the host), uploads fail silently
  and the video keeps `upload/photos/thumbnail.jpg` (the grey "no thumbnail" placeholder).
  The entrypoint `chown -R` fixes it on every container start; to fix a live container run
  `docker compose -f docker-compose.base44.yml exec -u root web chown -R www-data:www-data upload`.
- `php.ini` raises `memory_limit` to 512M because `PT_Resize_Crop_Image` loads the whole
  source image into GD to crop 1920x1080 thumbnails; the 128M default fails on large
  screenshots. `php.ini` is mounted read-only, so restart `web` after editing it.
- Payment gateways, SMTP and social-login credentials are in-app admin settings (stored in
  the `config` table), not environment variables. No external secret is required to boot.
- **Upload limits are `config` rows, not just php.ini.** `max_upload` is the per-file limit
  (seeded 10GB) and `max_upload_all_users` / `max_upload_free_users` / `max_upload_pro_users`
  are per-user storage quotas where `0` means unlimited (seeded `0`). `chunk_size` (24MB)
  keeps the plupload chunk requests under `post_max_size`; `php.ini` allows 10GB per request.
  The admin panel's Upload Settings page writes the same rows. Changing `playtube.sql` only
  affects a fresh import — an existing database needs
  `UPDATE config SET value='…' WHERE name='…'` (or the admin panel) to change limits.

## Verifying

```bash
curl -s http://localhost:3000/ | grep -qi '<title>PlayTube' && echo OK
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:3000/index.php?link1=login   # 200
curl -s -c /tmp/cj -X POST http://localhost:3000/index.php?link1=login \
  --data-urlencode 'username=admin' --data-urlencode 'password=PlayTube!Admin1' -o /dev/null -w '%{http_code}\n'  # 302
curl -s -b /tmp/cj -o /dev/null -w '%{http_code}\n' http://localhost:3000/admin-cp     # 200 signed in, 302 anonymous
```

There is no test suite in this repository (and no lint config for the vendored PHP).
