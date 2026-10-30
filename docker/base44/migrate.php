<?php
/**
 * Idempotent, additive schema for the Mux integration.
 * Run at container start by docker/base44/entrypoint.sh; safe to run any number of times.
 * It only ever CREATEs new tables — no existing table, row or asset is modified.
 */

$host = getenv('DB_HOST') ?: 'db';
$user = getenv('DB_USER') ?: 'playtube';
$pass = getenv('DB_PASSWORD') ?: 'playtube';
$name = getenv('DB_NAME') ?: 'playtube';

$link = null;
for ($attempt = 0; $attempt < 30; $attempt++) {
    $link = @new mysqli($host, $user, $pass, $name);
    if ($link && !$link->connect_error) {
        break;
    }
    $link = null;
    sleep(1);
}
if (!$link) {
    fwrite(STDERR, "mux migration: could not connect to the database\n");
    exit(1);
}
$link->set_charset('utf8mb4');

$statements = array(
    // One row per browser upload attempt. (user_id, client_key) is unique so a retried
    // upload reuses its row instead of creating duplicates.
    "CREATE TABLE IF NOT EXISTS `mux_videos` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `client_key` VARCHAR(64) NOT NULL,
        `video_id` VARCHAR(32) NOT NULL DEFAULT '',
        `upload_id` VARCHAR(64) NOT NULL DEFAULT '',
        `asset_id` VARCHAR(64) NOT NULL DEFAULT '',
        `playback_id` VARCHAR(128) NOT NULL DEFAULT '',
        `status` VARCHAR(20) NOT NULL DEFAULT 'waiting',
        `duration` DECIMAL(10,3) NULL DEFAULT NULL,
        `file_size` BIGINT NULL DEFAULT NULL,
        `mime_type` VARCHAR(64) NOT NULL DEFAULT '',
        `original_name` VARCHAR(255) NOT NULL DEFAULT '',
        `thumbnail_url` VARCHAR(255) NOT NULL DEFAULT '',
        `error_code` VARCHAR(64) NOT NULL DEFAULT '',
        `error_message` VARCHAR(255) NOT NULL DEFAULT '',
        `created_at` INT NOT NULL DEFAULT 0,
        `updated_at` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user_client` (`user_id`, `client_key`),
        KEY `idx_upload` (`upload_id`),
        KEY `idx_asset` (`asset_id`),
        KEY `idx_video` (`video_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Non-secret connection state for the admin setup screen.
    "CREATE TABLE IF NOT EXISTS `mux_state` (
        `name` VARCHAR(64) NOT NULL,
        `value` TEXT NULL,
        PRIMARY KEY (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
);

foreach ($statements as $sql) {
    if (!$link->query($sql)) {
        fwrite(STDERR, "mux migration failed: " . $link->error . "\n");
        exit(1);
    }
}

$link->close();
echo "mux migration: schema ok\n";
