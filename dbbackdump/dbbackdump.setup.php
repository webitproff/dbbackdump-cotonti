<?php
/* ====================
[BEGIN_COT_EXT]
Code=dbbackdump
Name=DB Backup
Description=Modern one-process database backup & restore (mysqldump preferred, pure-PHP fallback)
Version=3.0.1
Date=2026-10-16
Author=webitproff
Copyright=Copyright (c) 2026 webitproff
Auth_guests=R
Lock_guests=W12345A
Auth_members=R
Lock_members=W12345A
[END_COT_EXT]

[BEGIN_COT_EXT_CONFIG]
backup_dir=01:string::datas/dbbackdump:Папка бэкапов (относительно корня сайта или абсолютный путь)
keep_backups=02:string::14:Сколько последних копий оставлять (0 = без ограничения)
prefer_mysqldump=03:radio::1:Использовать mysqldump, если он доступен
mysqldump_path=04:string::/usr/bin/mysqldump:Путь к mysqldump
[END_COT_EXT_CONFIG]
==================== */

defined('COT_CODE') or die('Wrong URL');

// plugins/dbbackdump/dbbackdump.setup.php
