# Fusion-io driver for Unraid

Unraid plugin that loads the community **iomemory-vsl (VSL3)** driver for
Fusion-io / SanDisk **ioDrive, ioDrive2, ioScale and ioScale2** PCIe flash cards.
Unraid's kernel has no driver for these cards; with this plugin they show up
as `/dev/fioa`, `/dev/fiob`, …

The driver itself is [RemixVSL/iomemory-vsl](https://github.com/RemixVSL/iomemory-vsl),
the community-maintained version of SanDisk's last VSL 3.2.16 driver.
This repository only builds it for each Unraid kernel and loads it at boot.

> **Not for newer cards:** ioMemory SX300/SX350/PX600 (Gen3) need the VSL4
> driver, which this plugin does not include.

## Install

In the Unraid web interface go to **Plugins → Install Plugin**, paste this URL
and click **Install**:

```
https://raw.githubusercontent.com/LookAtMyC/unraid-fusionio/main/fusionio.plg
```

The plugin downloads the driver built for your exact kernel, checks its
SHA-256, stores it on the flash drive and loads it. It runs again on every
boot. Check the result with:

```bash
dmesg | grep fioinf
ls /dev/fio*
```

## Unraid updates

Each driver only works with the kernel it was built for. A GitHub Action checks
Unraid's release list every day and builds the driver for any new kernel, so a
build is normally available within a day of a new Unraid release.

After updating Unraid and rebooting, the plugin fetches the new build
automatically. If it is not available yet, the cards are simply absent and the
system log says so (`grep fusionio /var/log/syslog`). Reboot again once the
build is listed under [Releases](https://github.com/LookAtMyC/unraid-fusionio/releases).

## Show the cards in the Unraid web interface (optional)

Unraid's web interface and the Unassigned Devices plugin only list `sd`, `hd`
and `nvme` disks, so `/dev/fioX` does not show up there. The driver cannot
present itself as a SCSI disk (SanDisk's closed part of VSL3 lacks that code),
but the kernel's own **loopback SCSI target** can wrap each card as a normal
`/dev/sdX` disk. To turn this on, run once in the Unraid terminal:

```bash
fusionio-scsi enable
```

Each card then appears as an `sd` disk with vendor `FUSIONIO`, its model name
and its real serial number, so Unraid recognises it after every reboot. The
setting is stored in `/boot/config/plugins/fusionio/fusionio.cfg`
(`SD_DISKS="yes"`) and applied at every boot.

- `fusionio-scsi status` shows the current state, `fusionio-scsi disable`
  turns it off (unmount the disks / stop the array first).
- The data is the same: `sdX` reads and writes the card directly, nothing is
  converted. While a card is shown as `sdX`, `/dev/fioX` is locked so it can't
  be used twice by accident.
- The extra layer costs some speed: about 15–25 % lower sequential throughput
  in a quick test (1.15 GB/s instead of 1.4 GB/s on an ioScale).
- Tested with Unassigned Devices. Array/pool use is possible in principle but
  not tested yet.

## Using the cards

- Without the option above, the cards appear only as `/dev/fioX`. Formatting
  and mounting them manually works (e.g. `mkfs.xfs /dev/fioa` then mount under
  `/mnt/disks/...`).
- Firmware: the driver expects firmware 7.1.x (VSL 3.2.x). Very old cards may
  need a firmware update, done with SanDisk's `fio-update-iodrive` tool from
  another Linux system.
- `fio-status`, `fio-format` etc. are not included. They are SanDisk's
  proprietary utilities; see [RemixVSL/vsl_downloads](https://github.com/RemixVSL/vsl_downloads).

## Build it yourself

Needs only `bash` and `docker` (Linux, macOS, or Windows with Git Bash):

```bash
build/build.sh 7.1.4 out
```

This downloads the Unraid 7.1.4 release, extracts Unraid's kernel config and
patches, builds a matching kernel tree in a `gcc` container of the same
version Unraid used, compiles the driver and writes
`out/iomemory-vsl-<kernel>.ko`. Copy it to
`/boot/config/plugins/fusionio/` on the server and the plugin will use it.

## Warning

This is an unofficial, unsupported driver for hardware that is out of support.
It may cause data loss. Test before trusting it with data you care about.

## License

GPL-2.0, the same as the driver. The driver includes SanDisk's
binary `libkfio` component, distributed by the RemixVSL project.
