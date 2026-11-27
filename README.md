# Fusion-io driver for Unraid

This plugin makes old **Fusion-io / SanDisk flash cards** work in Unraid:
**ioDrive, ioDrive2, ioScale and ioScale2**.

Unraid doesn't come with a driver for these cards, so normally they are
invisible. After installing this plugin, your cards show up in the Unraid web
interface like any other disk.

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

3. Go to the **Main** tab. Your cards are listed under **Unassigned Devices**
   (vendor `FUSIONIO`, with the card's model name and serial number).

That's it. The plugin runs again at every boot, so the cards are always there.

## Things to know

- **Same card, new name.** Each card appears as a normal disk (for example
  `sdb`). Unraid recognises it by its serial number, so it stays the same disk
  after a reboot.
- **A little slower.** Showing the card as a normal disk costs some speed:
  roughly 15–25 % in our tests (about 1.15 GB/s instead of 1.4 GB/s on an
  ioScale). The cards are still very fast.
- **Using the cards in the array or a pool** should work, but we have only
  tested them with Unassigned Devices so far. Feedback is welcome.
- **Old data stays.** The plugin doesn't format or change anything on your
  cards. If they were used in another computer before, the old partitions are
  still there until you format them.

## After an Unraid update

Every Unraid version needs its own copy of the driver. We build it
automatically: every day we check for new Unraid versions and build a new
driver, usually within a day of an Unraid release.

After you update Unraid and restart, the plugin downloads the right driver by
itself. If it isn't ready yet, your cards are simply missing until it is.
Check the [Releases](https://github.com/LookAtMyC/unraid-fusionio/releases)
page, then restart once more when your version is listed.

## Commands (optional)

You can run these in the Unraid terminal. You don't need them for normal use.

| Command | What it does |
|---|---|
| `fusionio-scsi status` | Shows whether the cards are shown as normal disks |
| `fusionio-scsi disable` | Stops showing the cards as normal disks (now and after reboot). Stop the array or unmount the disks first. |
| `fusionio-scsi enable` | Turns it back on |

When it's turned off, the cards are only available as `/dev/fioa`,
`/dev/fiob`, … You won't see them in the web interface, but you can format
and mount them yourself from the terminal (full speed, no extra layer).

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

### How the cards become normal disks

The driver itself can only create `/dev/fioX` devices (SanDisk's closed part
of the driver has no SCSI support). The plugin then uses the Linux kernel's
built-in loopback SCSI target (`tcm_loop` with an `iblock` backstore) to show
each `/dev/fioX` as a SCSI disk `/dev/sdX`. Reads and writes go straight
through to the card. While a card is shown this way, `/dev/fioX` is locked so
it can't be used twice by accident. The setting is stored in
`/boot/config/plugins/fusionio/fusionio.cfg` (`SD_DISKS="yes"` or `"no"`).

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
