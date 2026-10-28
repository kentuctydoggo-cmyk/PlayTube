-- Dev seed for the Base44 sandbox. Safe to run repeatedly.
UPDATE `config` SET `value` = 'PlayTube' WHERE `name` = 'name';
UPDATE `config` SET `value` = 'PlayTube - Video CMS' WHERE `name` = 'title';
UPDATE `config` SET `value` = 'admin@playtube.local' WHERE `name` = 'email';
UPDATE `config` SET `value` = MD5(RAND()) WHERE `name` = 'apps_api_id';
UPDATE `config` SET `value` = MD5(RAND()) WHERE `name` = 'apps_api_key';
UPDATE `config` SET `value` = '1' WHERE `name` = 'is_ok';

-- Admin account: admin / PlayTube!Admin1 (legacy sha1; login re-hashes on first use).
INSERT INTO `users` (`username`, `email`, `password`, `admin`, `active`, `registered`, `time`)
SELECT 'admin', 'admin@playtube.local', SHA1('PlayTube!Admin1'), 1, 1, '00/0000', UNIX_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE `username` = 'admin');
