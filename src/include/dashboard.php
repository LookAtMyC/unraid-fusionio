<?php
/*
 * Rows of the Fusion-io Dashboard tile. Included by FusionIO_dashboard.page
 * when the Dashboard loads, and fetched again every few seconds to refresh.
 */
require_once __DIR__ . '/fusionio.php';

function fio_dash_rows(): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    if (!is_dir('/sys/module/iomemory_vsl')) {
        return "<tr class='fio-dash-row'><td>Driver not loaded. See <code>grep fusionio /var/log/syslog</code></td></tr>";
    }
    $cards = fio_cards();
    if (!$cards) return "<tr class='fio-dash-row'><td>No cards found</td></tr>";

    $out = '';
    foreach ($cards as $c) {
        $temp = $c['temp'] !== null ? round($c['temp']) . ' °C' : '? °C';
        $hot = $c['temp'] !== null && $c['temp'] >= 70 ? " style='color:#e68a00'" : '';
        if ($c['array_mode']) {
            $where = 'Array / pool' . ($c['sd'] ? ' (' . $h($c['sd']) . ')' : '');
        } elseif ($c['mountpoint']) {
            $where = $h($c['mountpoint']);
        } else {
            $where = 'Not mounted';
        }
        $pct = ($c['total'] && $c['used'] !== null) ? (int)round(100 * $c['used'] / $c['total']) : null;
        $bar = $pct === null ? '' :
            "<span class='load'>{$pct}%</span><div class='usage-disk sys'><span style='width:{$pct}%'></span><span></span></div>";

        $out .= "<tr class='fio-dash-row'><td>"
              . "<span class='w44'><b>" . $h($c['product']) . "</b><br><small>SN " . $h($c['serial']) . "</small></span>"
              . "<span class='w26'><span$hot>$temp</span><br><small>{$c['power_w']} W</small></span>"
              . "<span class='w26'><small>$where</small>$bar</span>"
              . "</td></tr>";
    }
    return $out;
}

// fetched by the tile's refresh script
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Cache-Control: no-store');
    echo fio_dash_rows();
}
