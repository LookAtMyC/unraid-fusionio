<?php
// GET /plugins/fusionio/include/status.php -> JSON list of cards
require_once __DIR__ . '/fusionio.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode([
    'driver' => is_dir('/sys/module/iomemory_vsl'),
    'cards'  => fio_cards(),
]);
