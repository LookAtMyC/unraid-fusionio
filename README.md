# Fusion-io driver for Unraid


<img width="646" height="181" alt="image" src="https://github.com/user-attachments/assets/ed892bac-26c1-4803-ba8b-1cddd298c8ae" />
<img width="1912" height="289" alt="image" src="https://github.com/user-attachments/assets/55771f05-273f-4982-bbcf-42a1675e3158" />



This plugin makes old **Fusion-io / SanDisk flash cards** work in Unraid:
**ioDrive, ioDrive2, ioScale and ioScale2**.

Unraid doesn't come with a driver for these cards, so normally they are
invisible. This plugin loads the driver and adds a **Fusion-io Devices**
section to the **Main** tab, where you manage your cards.

> **Needs Unraid 7.1.4 or newer.**
>
> **Newer cards are not supported.** The ioMemory SX300, SX350 and PX600 need a
> different driver (VSL4), which this plugin does not include.

## How to install

1. In the Unraid web interface, go to **Plugins → Install Plugin**.
2. Paste this link and click **Install**:

   ```
   https://raw.githubusercontent.com/LookAtMyC/unraid-fusionio/main/fusionio.plg
   ```

3. Go to the **Main** tab and scroll down to **Fusion-io Devices**.

The plugin runs again at every boot, so your cards are always there.

## What you can do on the Main tab

For each card you see its model, serial number, size, temperature and power
use, and you can:

- **Mount / Unmount** the card. It appears under `/mnt/disks/<name>`, the same
  place Unassigned Devices uses, so Docker containers and VMs can use it.
- **Auto mount**: mount the card automatically after every restart.
- **Format**: erase the card and create a new filesystem (XFS or BTRFS). You
  have to type the card's serial number to confirm, because this deletes
  everything on it.
- **Array / pool**: see below.

The **Dashboard** also gets a Fusion-io tile with each card's temperature,
power use, where it is mounted and how full it is.

## Using a card in the array or a pool

Unraid only lets "normal" disks into the array or a pool, and Fusion-io cards
aren't normal disks. Turn on the **Array / pool** switch for a card, and the
plugin shows it to Unraid as a normal disk (for example `sdb`). You can then
pick it when you add a disk to the array or a pool, or use it with
Unassigned Devices.

- **A little slower:** this costs some speed, roughly 15–25 % in our tests
  (about 1.15 GB/s instead of 1.4 GB/s on an ioScale). Leave the switch off for
  cards you just mount, so they run at full speed.
- **Same disk after a restart:** Unraid recognises the card by its serial
  number. The switch is remembered and applied at every boot, before the array
  starts.
- **Turning it off:** first remove the disk from the array or pool (or stop
  the array), then turn the switch off.
- Tested as a cache pool device.

## Things to know

- **Old data stays.** The plugin never formats or changes your cards unless
  you click **Format**. Cards that come from a Windows computer usually have
  NTFS on them. Those are mounted **read-only**, so you can look at and copy
  the old data without changing anything. Format the card to use it in Unraid.
- **Wear level** (how much life a card has left) isn't shown. The driver
  doesn't report it; only SanDisk's own `fio-status` tool can read it.

## After an Unraid update

Every Unraid version needs its own copy of the driver. We build it
automatically: every day we check for new Unraid versions and build a new
driver, usually within a day of an Unraid release.

After you update Unraid and restart, the plugin downloads the right driver by
itself. If it isn't ready yet, your cards are simply missing until it is.
Check the [Releases](https://github.com/LookAtMyC/unraid-fusionio/releases)
page, then restart once more when your version is listed.

## Commands (optional)

Everything on the Main tab also works in the Unraid terminal:

| Command | What it does |
|---|---|
| `fusionio status` | List your cards and their state |
| `fusionio mount fioa` / `fusionio unmount fioa` | Mount or unmount a card |
| `fusionio automount fioa yes` | Mount it automatically at boot (`no` to turn off) |
| `fusionio array fioa yes` | Show it as a normal disk for the array/pools (`no` to turn off) |
| `fusionio format fioa xfs mycard` | Erase the card and create a filesystem |

Instead of `fioa` you can also use the card's serial number.

To see if the driver is working: `dmesg | grep fioinf` or `ls /dev/fio*`.
Plugin messages are in the system log: `grep fusionio /var/log/syslog`.

## Problems

- **Cards not showing up:** check `grep fusionio /var/log/syslog`. Often the
  driver for a brand-new Unraid version just isn't built yet.
- **Firmware:** the driver needs card firmware 7.1.x. Very old cards may need
  a firmware update first, using SanDisk's tools on another Linux computer.
- **SanDisk tools** such as `fio-status` and `fio-format` aren't included.
  You can find them at [RemixVSL/vsl_downloads](https://github.com/RemixVSL/vsl_downloads).

Please report problems on the [Issues](https://github.com/LookAtMyC/unraid-fusionio/issues) page.

## Warning

This is an unofficial driver for hardware that SanDisk no longer supports.
It could lose data. Test it before you put anything important on the cards,
and keep backups.

## For developers

### Where the driver comes from

The driver is [RemixVSL/iomemory-vsl](https://github.com/RemixVSL/iomemory-vsl),
a community-maintained version of SanDisk's last driver (VSL 3.2.16). This
repository only builds it for each Unraid kernel and loads it at boot.

### How "Array / pool" mode works

The driver itself can only create `/dev/fioX` devices (SanDisk's closed part
of the driver has no SCSI support). For cards switched to array/pool mode, the
plugin uses the Linux kernel's built-in loopback SCSI target (`tcm_loop` with
an `iblock` backstore) to show `/dev/fioX` as a SCSI disk `/dev/sdX`, with the
card's real serial number. Reads and writes go straight through to the card.
While a card is in this mode, `/dev/fioX` is locked so it can't be used twice.
Per-card settings are stored in `/boot/config/plugins/fusionio/cards.cfg`.

### Plugin source

The plugin file `fusionio.plg` is generated from the files in `src/` by
`python3 build/make-plg.py`. Edit `src/` and regenerate; don't edit the
`.plg` directly.

### Building the driver yourself

You only need `bash` and `docker` (Linux, macOS, or Windows with Git Bash):

```bash
build/build.sh 7.1.4 out
```

This downloads the Unraid 7.1.4 release, takes its kernel settings, builds a
matching kernel with the same compiler version Unraid used, compiles the driver
and saves `out/iomemory-vsl-<kernel>.ko`. Copy that file to
`/boot/config/plugins/fusionio/` on your server and the plugin uses it instead
of downloading one.

GitHub Actions ([build.yml](.github/workflows/build.yml)) runs the same script
for every Unraid version since 7.1.4 and publishes the results as releases.
Older Unraid versions aren't built automatically, but `build/build.sh` works
for them too.

## License

GPL-2.0, the same as the driver. The driver includes SanDisk's closed-source
`libkfio` part, which the RemixVSL project distributes.
