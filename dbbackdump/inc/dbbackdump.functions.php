<?php
defined('COT_CODE') or die('Wrong URL');
// file plugins/dbbackdump/inc/dbbackdump.functions.php
/* ================================================================
   Core helpers
   ================================================================ */

function dbbackdump_dir(): string
{
    static $dir = null;
    if ($dir) return $dir;

    $cfg = trim((string)(Cot::$cfg['plugin']['dbbackdump']['backup_dir'] ?? ''));
    if ($cfg !== '') {
        $dir = rtrim($cfg, '/\\');
    } else {
        $base = 'datas';
        foreach (['cache_dir', 'avatars_dir', 'pfs_dir'] as $k) {
            if (!empty(Cot::$cfg[$k])) {
                $c = dirname(Cot::$cfg[$k]);
                if ($c && $c !== '.' && $c !== '/') {
                    $base = $c;
                    break;
                }
            }
        }
        $dir = rtrim($base, '/\\') . '/dbbackdump';
    }

    if (!is_dir($dir) && !@mkdir($dir, Cot::$cfg['dir_perms'] ?? 0775, true)) {
        throw new RuntimeException("Cannot create $dir");
    }
    if (!is_writable($dir)) {
        throw new RuntimeException("Not writable: $dir");
    }

    // security
    if (!is_file("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\nOptions -Indexes\n");
    }
    if (!is_file("$dir/index.php")) {
        file_put_contents("$dir/index.php", "<?php http_response_code(403);");
    }

    return $dir;
}

function dbbackdump_state_file(): string
{
    return dbbackdump_dir() . '/.job.json';
}

function dbbackdump_log(string $msg): void
{
    file_put_contents(dbbackdump_dir() . '/.log', date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function dbbackdump_write_state(array $s): void
{
    $tmp = dbbackdump_state_file() . '.tmp';
    file_put_contents($tmp, json_encode($s, JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, dbbackdump_state_file());
}

function dbbackdump_read_state(): array
{
    $f = dbbackdump_state_file();
    if (!is_file($f)) return [];
    $d = json_decode(file_get_contents($f) ?: '', true);
    return is_array($d) ? $d : [];
}

function dbbackdump_clear_state(): void
{
    @unlink(dbbackdump_state_file());
}

function dbbackdump_list(): array
{
    $list = [];
    foreach (glob(dbbackdump_dir() . '/*.sql.gz') ?: [] as $f) {
        $list[] = [
            'name' => basename($f),
            'size' => filesize($f),
            'date' => filemtime($f),
            'path' => $f,
        ];
    }
    usort($list, fn($a, $b) => $b['date'] <=> $a['date']);
    return $list;
}

function dbbackdump_delete(string $name): void
{
    $p = dbbackdump_dir() . '/' . basename($name);
    if (is_file($p) && preg_match('/\.sql\.gz$/i', $p)) {
        unlink($p);
    }
}

function dbbackdump_rotate(): void
{
    $keep = (int)(Cot::$cfg['plugin']['dbbackdump']['keep_backups'] ?? 14);
    if ($keep <= 0) return;
    foreach (array_slice(dbbackdump_list(), $keep) as $row) {
        @unlink($row['path']);
    }
}

function dbbackdump_engine_name(): string
{
    return dbbackdump_has_mysqldump() ? 'mysqldump (native)' : 'pure-PHP (cursor)';
}

function dbbackdump_has_mysqldump(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;

    if (empty(Cot::$cfg['plugin']['dbbackdump']['prefer_mysqldump'])) {
        return $ok = false;
    }
    $bin = trim(Cot::$cfg['plugin']['dbbackdump']['mysqldump_path'] ?? 'mysqldump') ?: 'mysqldump';
    $out = [];
    $code = -1;
    @exec(escapeshellcmd($bin) . ' --version 2>&1', $out, $code);
    return $ok = ($code === 0);
}

/* ================================================================
   Public AJAX API
   ================================================================ */

function dbbackdump_get_status(): array
{
    $s = dbbackdump_read_state();
    if (!$s) {
        return ['status' => 'idle'];
    }
    return [
        'status'   => $s['status'] ?? 'running',
        'percent'  => (int)($s['percent'] ?? 0),
        'message'  => $s['message'] ?? '',
        'file'     => $s['file'] ?? '',
        'error'    => $s['error'] ?? null,
    ];
}

function dbbackdump_cancel(): array
{
    $s = dbbackdump_read_state();
    if ($s) {
        $s['status'] = 'cancelled';
        dbbackdump_write_state($s);
    }
    return ['ok' => true];
}

function dbbackdump_start_dump(): array
{
    // уже идёт?
    $cur = dbbackdump_read_state();
    if (($cur['status'] ?? '') === 'running') {
        return dbbackdump_get_status();
    }

    // Запускаем в этом же запросе (один процесс)
    ignore_user_abort(true);
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');

    $file = 'backup_' . date('Y-m-d_H-i-s') . '.sql.gz';
    $path = dbbackdump_dir() . '/' . $file;

    dbbackdump_write_state([
        'status'  => 'running',
        'type'    => 'dump',
        'file'    => $file,
        'percent' => 0,
        'message' => 'Starting…',
        'started' => time(),
    ]);

    try {
        if (dbbackdump_has_mysqldump()) {
            dbbackdump_run_mysqldump($path);
        } else {
            dbbackdump_run_php_dump($path);
        }

        dbbackdump_rotate();
        dbbackdump_write_state([
            'status'  => 'done',
            'type'    => 'dump',
            'file'    => $file,
            'percent' => 100,
            'message' => 'Backup completed',
            'finished'=> time(),
        ]);
        dbbackdump_log("DUMP OK $file");
    } catch (Throwable $e) {
        @unlink($path);
        dbbackdump_write_state([
            'status'  => 'error',
            'error'   => $e->getMessage(),
            'message' => $e->getMessage(),
            'percent' => 0,
        ]);
        dbbackdump_log('DUMP FAIL: ' . $e->getMessage());
        throw $e;
    }

    return dbbackdump_get_status();
}

function dbbackdump_start_restore(string $name): array
{
    $cur = dbbackdump_read_state();
    if (($cur['status'] ?? '') === 'running') {
        return dbbackdump_get_status();
    }

    $name = basename($name);
    $path = dbbackdump_dir() . '/' . $name;
    if (!is_file($path) || !preg_match('/\.sql\.gz$/i', $name)) {
        throw new RuntimeException('Backup file not found');
    }

    ignore_user_abort(true);
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');

    dbbackdump_write_state([
        'status'  => 'running',
        'type'    => 'restore',
        'file'    => $name,
        'percent' => 0,
        'message' => 'Restoring…',
        'started' => time(),
    ]);

    try {
        dbbackdump_run_restore($path);
        dbbackdump_write_state([
            'status'  => 'done',
            'type'    => 'restore',
            'file'    => $name,
            'percent' => 100,
            'message' => 'Restore completed',
            'finished'=> time(),
        ]);
        dbbackdump_log("RESTORE OK $name");
    } catch (Throwable $e) {
        dbbackdump_write_state([
            'status'  => 'error',
            'error'   => $e->getMessage(),
            'message' => $e->getMessage(),
        ]);
        dbbackdump_log('RESTORE FAIL: ' . $e->getMessage());
        throw $e;
    }

    return dbbackdump_get_status();
}

/* ================================================================
   Native mysqldump
   ================================================================ */

function dbbackdump_run_mysqldump(string $path): void
{
    $bin  = trim(Cot::$cfg['plugin']['dbbackdump']['mysqldump_path'] ?? 'mysqldump') ?: 'mysqldump';
    $host = Cot::$cfg['mysqlhost'] ?? 'localhost';
    $user = Cot::$cfg['mysqluser'] ?? '';
    $pass = Cot::$cfg['mysqlpassword'] ?? '';
    $db   = Cot::$cfg['mysqldb'] ?? '';

    $cmd = escapeshellcmd($bin)
        . ' --host=' . escapeshellarg($host)
        . ' --user=' . escapeshellarg($user)
        . ' --single-transaction --quick --routines --triggers --hex-blob'
        . ' --default-character-set=utf8mb4 --set-gtid-purged=OFF'
        . ' ' . escapeshellarg($db);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = ['MYSQL_PWD' => $pass];

    $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start mysqldump');
    }
    fclose($pipes[0]);

    $gz = gzopen($path, 'wb6');
    if (!$gz) {
        proc_terminate($proc);
        throw new RuntimeException('Cannot create gzip file');
    }

    $bytes = 0;
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 131072);
        if ($chunk === false || $chunk === '') break;
        gzwrite($gz, $chunk);
        $bytes += strlen($chunk);

        // обновляем прогресс примерно
        if ($bytes % 5_000_000 < 131072) {
            dbbackdump_write_state([
                'status'  => 'running',
                'type'    => 'dump',
                'file'    => basename($path),
                'percent' => min(95, (int)($bytes / 1_000_000)),
                'message' => 'mysqldump… ' . round($bytes / 1048576, 1) . ' MB',
            ]);
        }
    }

    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    gzclose($gz);

    if ($code !== 0) {
        @unlink($path);
        throw new RuntimeException('mysqldump error: ' . trim($err));
    }
}

/* ================================================================
   Pure-PHP dump (cursor by PRIMARY KEY)
   ================================================================ */

function dbbackdump_run_php_dump(string $path): void
{
    $tables = Cot::$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $total  = count($tables);
    if (!$total) throw new RuntimeException('No tables');

    $gz = gzopen($path, 'wb6');
    if (!$gz) throw new RuntimeException('Cannot create file');

    $header = "-- Cotonti DB Backup (pure-PHP cursor)\n"
        . "-- " . date('c') . "\n"
        . "-- DB: " . Cot::$cfg['mysqldb'] . "\n\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n"
        . "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n";
    gzwrite($gz, $header);

    foreach ($tables as $i => $table) {
        $pct = (int)(($i / $total) * 100);
        dbbackdump_write_state([
            'status'  => 'running',
            'type'    => 'dump',
            'file'    => basename($path),
            'percent' => $pct,
            'message' => "Table $table (" . ($i + 1) . "/$total)",
        ]);

        // проверяем отмену
        $st = dbbackdump_read_state();
        if (($st['status'] ?? '') === 'cancelled') {
            gzclose($gz);
            @unlink($path);
            throw new RuntimeException('Cancelled by user');
        }

        $q = '`' . str_replace('`', '``', $table) . '`';

        // structure
        $create = Cot::$db->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM);
        if (!$create) continue;
        gzwrite($gz, "\nDROP TABLE IF EXISTS $q;\n{$create[1]};\n\n");

        // detect single-column PRIMARY KEY
        $pk = null;
        $keys = Cot::$db->query("SHOW KEYS FROM $q WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        if (count($keys) === 1) {
            $pk = $keys[0]['Column_name'];
        }

        $last = null;
        $batch = 1000;

        while (true) {
            if ($pk) {
                $pkq = '`' . str_replace('`', '``', $pk) . '`';
                if ($last === null) {
                    $sql = "SELECT * FROM $q ORDER BY $pkq ASC LIMIT $batch";
                } else {
                    $sql = "SELECT * FROM $q WHERE $pkq > " . Cot::$db->quote((string)$last)
                         . " ORDER BY $pkq ASC LIMIT $batch";
                }
            } else {
                // редкий случай — нет простого PK, берём всё сразу (осторожно)
                $sql = "SELECT * FROM $q";
                $batch = 999999;
            }

            $rows = Cot::$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) break;

            $cols = '(`' . implode('`,`', array_map(fn($c) => str_replace('`', '``', $c), array_keys($rows[0]))) . '`)';
            $values = [];
            foreach ($rows as $r) {
                $vals = [];
                foreach ($r as $v) {
                    if ($v === null) $vals[] = 'NULL';
                    elseif (is_int($v) || is_float($v)) $vals[] = $v;
                    else $vals[] = Cot::$db->quote((string)$v);
                }
                $values[] = '(' . implode(',', $vals) . ')';
                if ($pk) $last = $r[$pk];
            }
            gzwrite($gz, "INSERT INTO $q $cols VALUES\n" . implode(",\n", $values) . ";\n");

            if (!$pk || count($rows) < $batch) break;
        }
    }

    gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
}


/* ================================================================
   Restore (streaming)
   ================================================================ */
function dbbackdump_run_restore(string $path): void
{
    Cot::$db->query('SET FOREIGN_KEY_CHECKS=0');
    Cot::$db->query('SET NAMES utf8mb4');
    Cot::$db->query("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

    $fh = fopen('compress.zlib://' . $path, 'rb');
    if (!$fh) {
        throw new RuntimeException('Cannot open backup');
    }

    $buffer   = '';
    $i        = 0;                  // позиция сканирования — НЕ сбрасывается при fread
    $inStr    = false;
    $inBt     = false;
    $escape   = false;
    $executed = 0;
    $size     = filesize($path) ?: 1;

    while (!feof($fh)) {
        $chunk = fread($fh, 512 * 1024);
        if ($chunk === false) {
            break;
        }
        $buffer .= $chunk;

        $len = strlen($buffer);

        while ($i < $len) {
            $c = $buffer[$i];

            if ($escape) {
                $escape = false;
                $i++;
                continue;
            }

            // Обратный слэш внутри строки / бэктика
            if ($c === '\\' && ($inStr || $inBt)) {
                $escape = true;
                $i++;
                continue;
            }

            // Одинарная кавычка + SQL-экранирование ''
            if ($c === "'" && !$inBt) {
                if ($inStr && ($i + 1) < $len && $buffer[$i + 1] === "'") {
                    $i += 2;
                    continue;
                }
                $inStr = !$inStr;
                $i++;
                continue;
            }

            // Бэктик
            if ($c === '`' && !$inStr) {
                $inBt = !$inBt;
                $i++;
                continue;
            }

            // Конец SQL-запроса
            if ($c === ';' && !$inStr && !$inBt) {
                $sql = trim(substr($buffer, 0, $i));
                if ($sql !== '' && !str_starts_with($sql, '--') && !str_starts_with($sql, '/*')) {
                    try {
                        Cot::$db->query($sql);
                        $executed++;
                    } catch (Throwable $e) {
                        dbbackdump_log(
                            'SQL error: ' . $e->getMessage()
                            . ' | SQL head: ' . mb_substr($sql, 0, 300)
                        );
                    }
                }
                $buffer = substr($buffer, $i + 1);
                $len = strlen($buffer);
                $i = 0;    // сброс ТОЛЬКО здесь — когда буфер реально усечён слева

                if ($executed % 50 === 0) {
                    $pos = ftell($fh);
                    $pct = min(99, (int)(($pos / $size) * 100));
                    dbbackdump_write_state([
                        'status'  => 'running',
                        'type'    => 'restore',
                        'percent' => $pct,
                        'message' => "Executed $executed statements",
                    ]);
                    $st = dbbackdump_read_state();
                    if (($st['status'] ?? '') === 'cancelled') {
                        fclose($fh);
                        throw new RuntimeException('Cancelled');
                    }
                }
                continue;
            }

            $i++;
        }
    }

    if (trim($buffer) !== '') {
        try {
            Cot::$db->query(trim($buffer));
            $executed++;
        } catch (Throwable $e) {
            dbbackdump_log('SQL error (tail): ' . $e->getMessage());
        }
    }

    fclose($fh);
    Cot::$db->query('SET FOREIGN_KEY_CHECKS=1');
}