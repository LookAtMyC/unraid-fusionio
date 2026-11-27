<?php
// POST /plugins/fusionio/include/action.php
// Unraid checks csrf_token on every POST before this file runs.
require_once __DIR__ . '/fusionio.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$card   = $_POST['card'] ?? '';
$ok = true;
$msg = '';

try {
    $c = fio_card($card);
    switch ($action) {
        case 'mount':
            $msg = 'Mounted at ' . fio_mount($c['serial']);
            break;
        case 'unmount':
            fio_unmount($c['serial']);
            $msg = 'Unmounted';
            break;
        case 'automount':
            $on = ($_POST['value'] ?? '') === 'yes';
            fio_cfg_set($c['serial'], 'AUTOMOUNT', $on ? 'yes' : 'no');
            $msg = $on ? 'Will mount at boot' : 'Will not mount at boot';
            break;
        case 'array':
            $on = ($_POST['value'] ?? '') === 'yes';
            $on ? fio_array_on($c['serial']) : fio_array_off($c['serial']);
            fio_cfg_set($c['serial'], 'ARRAY', $on ? 'yes' : 'no');
            $msg = $on ? 'Card is now available for the array and pools' : 'Card is back in direct mode';
            break;
        case 'format':
            if (($_POST['confirm'] ?? '') !== $c['serial']) throw new FioError('confirmation did not match the serial number');
            fio_format($c['serial'], $_POST['fs'] ?? '', $_POST['label'] ?? '');
            $msg = 'Formatted';
            break;
        default:
            throw new FioError("unknown action '$action'");
    }
} catch (FioError $e) {
    $ok = false;
    $msg = $e->getMessage();
}
echo json_encode(['ok' => $ok, 'message' => $msg]);
