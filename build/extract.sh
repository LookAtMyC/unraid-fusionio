#!/usr/bin/env bash
# Stage 1 (runs in debian:bookworm): get the Unraid release, pull out the
# kernel config and Unraid's kernel patches, and record kernel/gcc versions.
# Writes /work/unraid-src/ and /work/kernel.env
set -euo pipefail
: "${UNRAID_VERSION:?}"

apt-get update -qq
apt-get install -y -qq --no-install-recommends \
  ca-certificates curl unzip squashfs-tools cpio xz-utils python3 >/dev/null

cd /work
ZIP="${UNRAID_ZIP:-/work/unRAIDServer-$UNRAID_VERSION-x86_64.zip}"
if [[ ! -f "$ZIP" ]]; then
  URL=$(curl -fsSL https://releases.unraid.net/json | python3 -c '
import json, sys
v = sys.argv[1]
for r in json.load(sys.stdin):
    if r["version"] == v:
        print(r["url"]); break
else:
    sys.exit("Unraid version %s not found in https://releases.unraid.net/json" % v)
' "$UNRAID_VERSION")
  echo "    downloading $URL"
  curl -fsSL -o "$ZIP" "$URL"
fi

rm -rf /work/zip /work/rootfs /work/unraid-src
mkdir -p /work/zip /work/rootfs /work/unraid-src
unzip -q -o "$ZIP" 'bz*' -d /work/zip

# The kernel source stub (/usr/src/linux-*-Unraid) lives in one of the
# images; try the squashfs ones first, then the bzroot initramfs.
for img in bzfirmware bzmodules; do
  [[ -f /work/zip/$img ]] || continue
  unsquashfs -q -f -d "/work/rootfs/$img" "/work/zip/$img" 'src' 'usr/src' >/dev/null 2>&1 || true
done
SRC=$(find /work/rootfs -maxdepth 4 -type d -name 'linux-*-Unraid' | head -1)

if [[ -z "$SRC" ]]; then
  # bzroot = optional uncompressed microcode cpio + xz cpio of the rootfs.
  mkdir -p /work/rootfs/bzroot
  python3 - /work/zip/bzroot /work/rootfs/bzroot.xz <<'PY'
import sys
data = open(sys.argv[1], "rb").read()
i = data.find(b"\xfd7zXZ\x00")
if i < 0:
    sys.exit("no xz stream in bzroot")
open(sys.argv[2], "wb").write(data[i:])
PY
  (cd /work/rootfs/bzroot && xz -dc ../bzroot.xz | cpio -idm --quiet 'usr/src/*' 2>/dev/null || true)
  SRC=$(find /work/rootfs -maxdepth 4 -type d -name 'linux-*-Unraid' | head -1)
fi

[[ -n "$SRC" ]] || { echo "ERROR: kernel source stub not found in Unraid $UNRAID_VERSION" >&2; exit 1; }
echo "    found $(basename "$SRC")"
cp -a "$SRC/.config" /work/unraid-src/
cp -a "$SRC"/*.patch /work/unraid-src/ 2>/dev/null || true

KERNEL_RELEASE=$(basename "$SRC" | sed 's/^linux-//')     # 6.12.24-Unraid
KERNEL_BASE=${KERNEL_RELEASE%-Unraid}                      # 6.12.24
GCC_VERSION=$(sed -n 's/^CONFIG_CC_VERSION_TEXT="gcc (GCC) \([0-9.]*\).*/\1/p' /work/unraid-src/.config)
[[ -n "$GCC_VERSION" ]] || { echo "ERROR: could not read gcc version from .config" >&2; exit 1; }

cat > /work/kernel.env <<EOF
UNRAID_VERSION=$UNRAID_VERSION
KERNEL_RELEASE=$KERNEL_RELEASE
KERNEL_BASE=$KERNEL_BASE
GCC_VERSION=$GCC_VERSION
EOF
rm -rf /work/zip /work/rootfs
