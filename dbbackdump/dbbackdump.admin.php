<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=tools
[END_COT_EXT]
==================== */
// plugins/dbbackdump/dbbackdump.admin.php
defined('COT_CODE') or die('Wrong URL');

require_once cot_incfile('dbbackdump', 'plug');

if (!cot_auth('plug', 'dbbackdump', 'A')) {
    cot_die_message(403, true);
}

$act = cot_import('act', 'G', 'ALP');

if ($act === 'delete') {
    cot_check_xg();
    dbbackdump_delete(cot_import('name', 'G', 'TXT'));
    cot_message(Cot::$L['dbbackdump_deleted']);
    cot_redirect(cot_url('admin', 'm=other&p=dbbackdump', '', true));
}

$adminTitle = Cot::$L['dbbackdump_title'];
$t = new XTemplate(cot_tplfile('dbbackdump.admin', 'plug'));

$ajaxBase = cot_url('index', ['r' => 'dbbackdump'], '', true);
$xkEnc    = rawurlencode(Cot::$sys['xk']);
$pageBase = cot_url('admin', 'm=other&p=dbbackdump', '', true);

foreach (dbbackdump_list() as $i => $row) {
    $nameEnc = rawurlencode($row['name']);

    $deleteUrl = $pageBase . '&act=delete&name=' . $nameEnc . '&x=' . Cot::$sys['xk'];

    $deleteConfirmUrl = cot_confirm_url(
        $deleteUrl,
        'dbbackdump',
        'dbbackdump_confirm_delete'
    );

    $t->assign([
        'BACKUP_NAME'     => htmlspecialchars($row['name']),
        'BACKUP_SIZE'     => cot_build_filesize($row['size']),
        'BACKUP_DATE'     => cot_date('datetime_medium', $row['date']),
        'BACKUP_NAME_ENC' => $nameEnc,
        'BACKUP_URL_DL'   => $ajaxBase . '&a=download&name=' . $nameEnc . '&x=' . $xkEnc,
        'BACKUP_URL_DEL'  => $deleteConfirmUrl,
        'BACKUP_ODDEVEN'  => cot_build_oddeven($i + 1),
    ]);
    $t->parse('MAIN.ROW');
}

$t->assign([
    'BACKUP_AJAX'   => $ajaxBase,
    'BACKUP_XK'     => Cot::$sys['xk'],
    'BACKUP_ENGINE' => dbbackdump_engine_name(),
]);

cot_display_messages($t);
$t->parse('MAIN');
$adminMain = $t->text('MAIN');