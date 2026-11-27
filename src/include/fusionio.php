<?php
/*
 * Fusion-io plugin for Unraid: card detection, mounting, formatting and the
 * optional "array/pool mode" (show a card as a normal sd disk through the
 * kernel's loopback SCSI target).
 *
 * Used by the Main page section (status.php, action.php) and by the
 * command line tool /usr/local/sbin/fusionio.
 */

const FIO_CFG_DIR   = '/boot/config/plugins/fusionio';
const FIO_CARDS_CFG = FIO_CFG_DIR . '/cards.cfg';
const FIO_MNT_BASE  = '/mnt/disks';
const FIO_TGT       = '/sys/kernel/config/target';
const FIO_TGT_WWN   = 'naa.5001405f10000001';   // loopback target port
const FIO_INIT_WWN  = 'naa.5001405f10000002';   // loopback initiator port
const FIO_TPG       = FIO_TGT . '/loopback/' . FIO_TGT_WWN . '/tpgt_1';

class FioError extends Exception {}

function fio_run(string $cmd, ?int &$rc = null): string {
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    return trim(implode("\n", $out));
}

function fio_log(string $msg): void {
    exec('logger -t fusionio ' . escapeshellarg($msg));
}

function fio_read(string $path): string {
    $s = @file_get_contents($path);
    return $s === false ? '' : trim($s);
}

function fio_write(string $path, string $value): void {
    if (@file_put_contents($path, $value) === false) {
        throw new FioError("could not write '$value' to $path");
    }
}

/* ---------- settings (per card, keyed by serial number) ---------- */

function fio_cfg(): array {
    if (!is_file(FIO_CARDS_CFG)) return [];
    $c = @parse_ini_file(FIO_CARDS_CFG);
    return is_array($c) ? $c : [];
}

function fio_cfg_get(string $serial, string $key, string $default = 'no'): string {
    $c = fio_cfg();
    return $c["{$serial}_{$key}"] ?? $default;
}

function fio_cfg_set(string $serial, string $key, string $value): void {
    $c = fio_cfg();
    $c["{$serial}_{$key}"] = $value;
    @mkdir(FIO_CFG_DIR, 0755, true);
    $lines = [];
    foreach ($c as $k => $v) $lines[] = $k . '="' . $v . '"';
    file_put_contents(FIO_CARDS_CFG, implode("\n", $lines) . "\n");
}

/* ---------- card detection ---------- */

/** All cards the driver has attached, with live information. */
function fio_cards(): array {
    $cards = [];
    $mounts = fio_mounts();
    $sd = fio_sd_by_serial();
    foreach (glob('/dev/fio[a-z]') ?: [] as $dev) {
        $name = basename($dev);
        $fct = explode(':', fio_read("/proc/fusion/fio/$name/data_device"))[0];
        $info = fio_read("/proc/fusion/iodrive/$fct/board_info");
        preg_match('/Product:\s*(.+)/', $info, $m);
        $product = trim(preg_replace('/^Fusion-io\s+/', '', $m[1] ?? 'ioMemory'));
        preg_match('/SN:\s*(\S+)/', $info, $m);
        $serial = preg_replace('/[^A-Za-z0-9]/', '', $m[1] ?? $name);

        // hottest sensor on the card
        $temp = null;
        foreach (explode("\n", fio_read("/proc/fusion/$fct/smp/sensors")) as $line) {
            if (preg_match('/deg_C\s+([\d.]+)/', $line, $t)) $temp = max($temp ?? 0, (float)$t[1]);
        }
        preg_match('/ecc errors:\s*(\d+)/', fio_read("/proc/fusion/iodrive/$fct/ecc"), $e);
        $stat = preg_split('/\s+/', fio_read("/sys/block/$name/stat"));

        $arrayMode = is_link(FIO_TPG . '/lun/lun_' . fio_lun($name) . "/$name");
        $sdName = $sd[$serial] ?? null;

        // The partition to use: the largest one with a filesystem (cards from
        // Windows often have a small system partition first), else the whole card.
        $part = $dev;
        $fs = $label = '';
        if (!$arrayMode) {
            $best = -1;
            $parts = glob("/sys/block/$name/{$name}*") ?: [];
            foreach (array_merge([$dev], array_map(fn($p) => '/dev/' . basename($p), $parts)) as $cand) {
                $blk = fio_run('blkid -o export ' . escapeshellarg($cand));
                if (!preg_match('/^TYPE=(.*)$/m', $blk, $t)) continue;
                $size = (int)fio_read('/sys/class/block/' . basename($cand) . '/size');
                if ($size > $best) {
                    $best = $size;
                    $part = $cand;
                    $fs = $t[1];
                    $label = preg_match('/^LABEL=(.*)$/m', $blk, $l) ? $l[1] : '';
                }
            }
        }

        $mountpoint = $mounts[$part] ?? null;
        $used = $total = null;
        if ($mountpoint) {
            $total = @disk_total_space($mountpoint);
            $used = $total !== false ? $total - @disk_free_space($mountpoint) : null;
        }

        $cards[] = [
            'dev'        => $name,
            'part'       => $part,
            'product'    => $product,
            'serial'     => $serial,
            'size'       => (int)fio_read("/sys/block/$name/size") * 512,
            'temp'       => $temp,
            'power_w'    => round((int)fio_read("/proc/fusion/iodrive/$fct/power_monitor/power_milliwatts") / 1000, 1),
            'ecc_errors' => isset($e[1]) ? (int)$e[1] : null,
            'written'    => isset($stat[6]) ? (int)$stat[6] * 512 : null,   // since boot
            'fs'         => $fs,
            'label'      => $label,
            'mountpoint' => $mountpoint,
            'used'       => $used,
            'total'      => $total ?: null,
            'automount'  => fio_cfg_get($serial, 'AUTOMOUNT') === 'yes',
            'array_mode' => $arrayMode,
            'sd'         => $sdName,
        ];
    }
    return $cards;
}

/** Find a card by serial number or device name (fioa). */
function fio_card(string $id): array {
    foreach (fio_cards() as $c) {
        if ($c['serial'] === $id || $c['dev'] === $id || "/dev/{$c['dev']}" === $id) return $c;
    }
    throw new FioError("no Fusion-io card '$id'");
}

/** device => mountpoint */
function fio_mounts(): array {
    $m = [];
    foreach (file('/proc/mounts') ?: [] as $line) {
        $f = explode(' ', $line);
        if (count($f) > 1) $m[$f[0]] = str_replace('\040', ' ', $f[1]);
    }
    return $m;
}

/** serial => sdX for disks provided by our loopback target */
function fio_sd_by_serial(): array {
    $r = [];
    foreach (glob('/sys/block/sd*') ?: [] as $b) {
        if (fio_read("$b/device/vendor") !== 'FUSIONIO') continue;
        $sd = basename($b);
        $serial = fio_run('lsblk -dno SERIAL ' . escapeshellarg("/dev/$sd"));
        if ($serial !== '') $r[$serial] = $sd;
    }
    return $r;
}

function fio_lun(string $dev): int {
    return ord(substr($dev, -1)) - ord('a');
}

/* ---------- mount / unmount / format ---------- */

function fio_mount(string $id): string {
    $c = fio_card($id);
    if ($c['array_mode']) throw new FioError("{$c['product']} is in array/pool mode; turn that off first");
    if ($c['mountpoint']) return $c['mountpoint'];
    if ($c['fs'] === '') throw new FioError("{$c['product']} has no filesystem; format it first");
    $name = $c['label'] !== '' ? $c['label'] : 'fusionio_' . $c['serial'];
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    $mp = FIO_MNT_BASE . '/' . $name;
    if (is_dir($mp) && fio_read_dir_nonempty($mp)) throw new FioError("$mp already exists and is not empty");
    @mkdir($mp, 0777, true);
    $fsType = $c['fs'];
    if (in_array($fsType, ['xfs', 'btrfs', 'ext4'])) {
        $opts = '-o noatime';
    } elseif ($fsType === 'ntfs') {
        // Windows filesystem: read-only, so data from a Windows machine stays untouched
        $fsType = 'ntfs3';
        $opts = '-o ro,iocharset=utf8';
    } else {
        $opts = '';
    }
    $out = fio_run("mount -t " . escapeshellarg($fsType) . " $opts " . escapeshellarg($c['part']) . ' ' . escapeshellarg($mp), $rc);
    if ($rc !== 0) { @rmdir($mp); throw new FioError("mount failed: $out"); }
    @chmod($mp, 0777);
    fio_log("mounted {$c['part']} ({$c['product']}, SN {$c['serial']}) at $mp");
    return $mp;
}

function fio_read_dir_nonempty(string $dir): bool {
    return count(array_diff(scandir($dir) ?: [], ['.', '..'])) > 0;
}

function fio_unmount(string $id): void {
    $c = fio_card($id);
    if (!$c['mountpoint']) return;
    $out = fio_run('umount ' . escapeshellarg($c['mountpoint']), $rc);
    if ($rc !== 0) throw new FioError("unmount failed (is something still using it?): $out");
    @rmdir($c['mountpoint']);
    fio_log("unmounted {$c['part']} ({$c['product']}, SN {$c['serial']})");
}

function fio_format(string $id, string $fs, string $label): void {
    $c = fio_card($id);
    if (!in_array($fs, ['xfs', 'btrfs'], true)) throw new FioError("filesystem must be xfs or btrfs");
    if (!preg_match('/^[A-Za-z0-9_-]{1,12}$/', $label)) throw new FioError("name must be 1-12 characters: letters, digits, - or _");
    if ($c['array_mode']) throw new FioError("{$c['product']} is in array/pool mode; turn that off first");
    if ($c['mountpoint']) throw new FioError("{$c['product']} is mounted; unmount it first");
    $dev = '/dev/' . $c['dev'];
    $q = escapeshellarg($dev);
    fio_log("formatting $dev ({$c['product']}, SN {$c['serial']}) as $fs, name $label");
    foreach (glob("/sys/block/{$c['dev']}/{$c['dev']}*") ?: [] as $p) {
        fio_run('wipefs -a ' . escapeshellarg('/dev/' . basename($p)));
    }
    fio_run("wipefs -a $q");
    fio_run("sgdisk -Z $q");
    $out = fio_run("sgdisk -n 1:2048:0 -t 1:8300 $q", $rc);
    if ($rc !== 0) throw new FioError("partitioning failed: $out");
    fio_run("partprobe $q; udevadm settle");
    $part = "/dev/{$c['dev']}1";
    for ($i = 0; $i < 20 && !file_exists($part); $i++) usleep(250000);
    if (!file_exists($part)) throw new FioError("partition $part did not appear");
    $cmd = $fs === 'xfs' ? 'mkfs.xfs -f -L ' : 'mkfs.btrfs -f -L ';
    $out = fio_run($cmd . escapeshellarg($label) . ' ' . escapeshellarg($part), $rc);
    if ($rc !== 0) throw new FioError("mkfs failed: $out");
    fio_log("formatted $part as $fs");
}

/* ---------- array/pool mode (loopback SCSI disk) ---------- */

function fio_scsi_host(): ?string {
    foreach (glob('/sys/class/scsi_host/host*/proc_name') ?: [] as $p) {
        if (fio_read($p) === 'tcm_loopback') return dirname($p);
    }
    return null;
}

function fio_array_on(string $id): void {
    $c = fio_card($id);
    if ($c['array_mode']) return;
    if ($c['mountpoint']) throw new FioError("{$c['product']} is mounted; unmount it first");
    fio_run('modprobe target_core_iblock; modprobe tcm_loop');
    if (!is_dir(FIO_TGT)) fio_run('mount -t configfs none /sys/kernel/config');
    if (!is_dir(FIO_TGT . '/loopback')) throw new FioError('kernel loopback SCSI target is not available');

    @mkdir(FIO_TPG, 0755, true);
    if (fio_read(FIO_TPG . '/nexus') === '') fio_write(FIO_TPG . '/nexus', FIO_INIT_WWN);

    $name = $c['dev'];
    $bs = FIO_TGT . "/core/iblock_0/$name";
    @mkdir($bs, 0755, true);
    fio_write("$bs/control", "udev_path=/dev/$name");
    fio_write("$bs/wwn/vpd_unit_serial", $c['serial']);
    if (@file_put_contents("$bs/enable", '1') === false) {
        @rmdir($bs);
        throw new FioError("/dev/$name is in use and cannot be switched");
    }
    @file_put_contents("$bs/wwn/vendor_id", 'FUSIONIO');
    @file_put_contents("$bs/wwn/product_id", substr($c['product'], 0, 16));
    if (fio_read("/sys/block/$name/queue/discard_max_bytes") !== '0') {
        @file_put_contents("$bs/attrib/emulate_tpu", '1');   // pass TRIM through
    }
    $lun = FIO_TPG . '/lun/lun_' . fio_lun($name);
    @mkdir($lun, 0755, true);
    if (!@symlink($bs, "$lun/$name")) throw new FioError("could not create SCSI LUN for /dev/$name");

    if ($h = fio_scsi_host()) @file_put_contents("$h/scan", '- - -');
    fio_run('udevadm settle');
    fio_log("{$c['product']} (SN {$c['serial']}) now shown as a SCSI disk for array/pool use");
}

function fio_array_off(string $id): void {
    $c = fio_card($id);
    if (!$c['array_mode']) return;
    $name = $c['dev'];
    if ($c['sd']) {
        $sd = $c['sd'];
        $mounts = fio_mounts();
        foreach (array_merge(["/dev/$sd"], array_map(fn($p) => '/dev/' . basename($p), glob("/sys/block/$sd/$sd*") ?: [])) as $d) {
            if (isset($mounts[$d])) throw new FioError("$d is mounted; stop the array or unmount it first");
            $h = '/sys/class/block/' . basename($d) . '/holders';
            if (glob("$h/*")) throw new FioError("$d is in use (array, pool or RAID); remove it there first");
        }
        @file_put_contents("/sys/block/$sd/device/delete", '1');
    }
    $lun = FIO_TPG . '/lun/lun_' . fio_lun($name);
    @unlink("$lun/$name");
    @rmdir($lun);
    @rmdir(FIO_TGT . "/core/iblock_0/$name");
    if (!glob(FIO_TPG . '/lun/lun_*')) {
        @file_put_contents(FIO_TPG . '/nexus', 'NULL');
        @rmdir(FIO_TPG);
        @rmdir(FIO_TGT . '/loopback/' . FIO_TGT_WWN);
    }
    fio_log("{$c['product']} (SN {$c['serial']}) back to direct mode (/dev/$name)");
}

/* ---------- boot ---------- */

function fio_boot(): void {
    foreach (fio_cards() as $c) {
        try {
            if (fio_cfg_get($c['serial'], 'ARRAY') === 'yes') {
                fio_array_on($c['serial']);
            } elseif (fio_cfg_get($c['serial'], 'AUTOMOUNT') === 'yes') {
                fio_mount($c['serial']);
            }
        } catch (FioError $e) {
            fio_log("{$c['serial']}: " . $e->getMessage());
        }
    }
}
