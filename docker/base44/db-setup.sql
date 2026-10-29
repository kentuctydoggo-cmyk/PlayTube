-- Dev seed for the Base44 sandbox. Safe to run repeatedly.
UPDATE `config` SET `value` = 'PlayTube' WHERE `name` = 'name';
UPDATE `config` SET `value` = 'PlayTube - Video CMS' WHERE `name` = 'title';
UPDATE `config` SET `value` = 'admin@playtube.local' WHERE `name` = 'email';
UPDATE `config` SET `value` = MD5(RAND()) WHERE `name` = 'apps_api_id';
UPDATE `config` SET `value` = MD5(RAND()) WHERE `name` = 'apps_api_key';
UPDATE `config` SET `value` = '1' WHERE `name` = 'is_ok';

-- Admin account: admin@gmail.com / Vazquez7689@ (legacy sha1; login re-hashes on first use).
INSERT INTO `users` (`username`, `email`, `password`, `admin`, `active`, `registered`, `time`)
SELECT 'admin', 'admin@gmail.com', SHA1('Vazquez7689@'), 1, 1, '00/0000', UNIX_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');

-- Move installs seeded before the admin email changed.
UPDATE `users` SET `email` = 'admin@gmail.com' WHERE `username` = 'admin' AND `email` = 'admin@playtube.local';
