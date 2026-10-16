<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=ajax
[END_COT_EXT]
==================== */
// plugins/dbbackdump/dbbackdump.ajax.php
defined('COT_CODE') or die('Wrong URL');

require_once cot_incfile('dbbackdump', 'plug');

if (!cot_auth('plug', 'dbbackdump', 'A')) {
    cot_die_message(403, true);
}
cot_check_xg();

$action = cot_import('a', 'G', 'ALP');

/* ---- Download: отдаём бинарник, НЕ JSON ---- */
if ($action === 'download') {
    while (ob_get_level()) { ob_end_clean(); }
    $name = basename(cot_import('name', 'G', 'TXT'));
    $path = dbbackdump_dir() . '/' . $name;
    if (!is_file($path) || !preg_match('/\.sql\.gz$/i', $name)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

/* ---- Всё остальное — JSON ---- */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $data = match ($action) {
        'start_dump'    => dbbackdump_start_dump(),
        'start_restore' => dbbackdump_start_restore(cot_import('name', 'G', 'TXT')),
        'status'        => dbbackdump_get_status(),
        'cancel'        => dbbackdump_cancel(),
        default         => throw new RuntimeException('Unknown action'),
    };
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}