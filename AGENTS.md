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

Admin login: `admin@gmail.com` / `Vazquez7689@` (username `admin`; the sandbox database stores
it as a `password_hash` hash, fresh seeds as legacy SHA1 that the login flow re-hashes on
first successful sign-in).

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
- **The 18+ Section page is a custom addition** (`sources/adult/content.php` +
  `themes/youplay/layout/adult/content.html`, linked from the sidebar in
  `themes/youplay/layout/container.html`). It lists public, approved long-form videos whose
  `videos.age_restriction` is `2` ("Only 18+" in the upload/edit form); `1` means all ages.
  The route resolves through the `.htaccess` catch-all, so no rewrite rule is needed. The
  label is hardcoded in the template rather than added as a `langs` row.
- Payment gateways, SMTP and social-login credentials are in-app admin settings (stored in
  the `config` table), not environment variables. No external secret is required to boot.
- **Upload limits are `config` rows, not just php.ini.** `max_upload` is the per-file limit
  (seeded 10GB) and `max_upload_all_users` / `max_upload_free_users` / `max_upload_pro_users`
  are per-user storage quotas where `0` means unlimited (seeded `0`). `chunk_size` (24MB)
  keeps the plupload chunk requests under `post_max_size`; `php.ini` allows 10GB per request.
  The admin panel's Upload Settings page writes the same rows. Changing `playtube.sql` only
  affects a fresh import — an existing database needs
  `UPDATE config SET value='…' WHERE name='…'` (or the admin panel) to change limits.
- **Storage capacity is the sandbox disk, not a config row.** Per-user quotas are already
  `0` (unlimited) and the per-file cap is 10GB. The real ceiling is the single 47GB volume
  the checkout lives on (~25GB free with the current sample videos); `upload/videos/` is
  gitignored so multi-GB uploads never bloat the repo or fail the push. For genuinely
  unlimited long-term storage use the app's own offload: Amazon S3, Wasabi, Backblaze B2 or
  FTP (`s3_upload` / `wasabi_storage` / `backblaze_storage` / `ftp_upload` in `config`, set
  from the admin panel's storage pages) — that needs the user's bucket credentials.
- **The uploader posts to `/aj/upload-video`, not `/ajax/upload-video.php`.** The `aj/…`
  rewrite goes through `ajax.php`, which boots the app and defines `IS_LOGGED`, `$pt`, `$db`;
  hitting the raw file answers `{"status":400,"error":"Not logged in"}` plus a
  "undefined constant IS_LOGGED" warning. Uploads are chunked (`chunk_size`, 24MB) and the
  server appends each chunk to `<file>.part`, renaming it on the last one — so a single
  request is never bigger than `post_max_size`. A leftover `.part` plus a `pending` row in
  `uploaded_chunks` just means an upload was abandoned; the next attempt for the same file
  name restarts chunk 0 and truncates it.

## Verifying

```bash
curl -s http://localhost:3000/ | grep -qi '<title>PlayTube' && echo OK
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:3000/index.php?link1=login   # 200
curl -s -c /tmp/cj -X POST http://localhost:3000/index.php?link1=login \
  --data-urlencode 'username=admin' --data-urlencode 'password=Vazquez7689@' -o /dev/null -w '%{http_code}\n'  # 302
curl -s -b /tmp/cj -o /dev/null -w '%{http_code}\n' http://localhost:3000/admin-cp     # 200 signed in, 302 anonymous
```

Video upload, end to end (chunks the way the browser's plupload does, then publish):

```bash
HASH=$(curl -s -b /tmp/cj http://localhost:3000/upload-video \
  | grep -o 'class="main_session" value="[a-f0-9]*"' | grep -o '[a-f0-9]\{40\}')
split -b 24m -d -a 1 clip.mp4 part_          # 24MB = config chunk_size
for f in part_*; do
  curl -s -b /tmp/cj "http://localhost:3000/aj/upload-video?hash=$HASH" \
    -F "video=@$f;filename=clip.mp4;type=video/mp4" -F "name=clip.mp4" \
    -F "chunk=$((i++))" -F "chunks=$(ls part_* | wc -l)"      # last chunk returns file_path
done
curl -s -b /tmp/cj "http://localhost:3000/aj/submit-video?hash=$HASH" \
  -F "title=test" -F "description=test" -F "tags=test" -F "privacy=1" \
  -F "video-location=<file_path from the last chunk>" -F "thumbnail=@thumb.jpg;type=image/jpeg"
```

`upload/videos/YYYY/MM` must stay writable by `www-data` for the same reason as `photos`.
Delete test videos with `DELETE FROM videos WHERE video_id='…'` plus `rm` of the file and its
thumbnail; a published video otherwise stays in the feed.

There is no test suite in this repository (and no lint config for the vendored PHP).
