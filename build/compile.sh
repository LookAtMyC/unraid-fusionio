#!/usr/bin/env bash
# Stage 2 (runs in gcc:<same version Unraid used>): prepare a kernel tree that
# matches the Unraid kernel, then compile iomemory-vsl against it.
# Reads /work/kernel.env and /work/unraid-src/, writes to /out/
set -euo pipefail
# shellcheck disable=SC1091
source /work/kernel.env
: "${VSL_REPO:?}" "${VSL_REF:?}"

apt-get update -qq
apt-get install -y -qq --no-install-recommends \
  bc flex bison libelf-dev libssl-dev xz-utils cpio kmod rsync git perl >/dev/null

# The driver's build scripts ask `uname -r` for the target kernel; answer
# with the Unraid kernel instead of the build host's.
cat > /usr/local/bin/uname <<EOF
#!/bin/sh
case "\$1" in
  -r) echo "$KERNEL_RELEASE" ;;
  -m) echo x86_64 ;;
  *)  exec /bin/uname "\$@" ;;
esac
EOF
chmod +x /usr/local/bin/uname

JOBS=$(nproc)
MAJOR=${KERNEL_BASE%%.*}
TARBALL=/work/linux-$KERNEL_BASE.tar.xz
if [[ ! -f $TARBALL ]]; then
  echo "    downloading linux-$KERNEL_BASE"
  curl -fsSL -o "$TARBALL.part" "https://cdn.kernel.org/pub/linux/kernel/v$MAJOR.x/linux-$KERNEL_BASE.tar.xz"
  mv "$TARBALL.part" "$TARBALL"
fi

KSRC=/build/linux-$KERNEL_BASE
mkdir -p /build
tar -xf "$TARBALL" -C /build
cd "$KSRC"
for p in /work/unraid-src/*.patch; do
  [[ -f $p ]] || continue
  patch -p1 --forward -s <"$p" || echo "    note: Unraid patch $(basename "$p") did not apply (usually harmless)"
done
cp /work/unraid-src/.config .config
make olddefconfig >/dev/null
[[ "$(make -s kernelrelease)" == "$KERNEL_RELEASE" ]] || {
  echo "ERROR: kernel tree reports $(make -s kernelrelease), expected $KERNEL_RELEASE" >&2; exit 1; }

# Building vmlinux gives an exact list of exported symbols (Module.symvers),
# which the driver's feature probes and modpost need.
echo "    building vmlinux with $JOBS jobs (this takes a while)"
make -j"$JOBS" vmlinux >/work/vmlinux-build.log 2>&1 || { tail -50 /work/vmlinux-build.log; exit 1; }
cp vmlinux.symvers Module.symvers
make -j"$JOBS" modules_prepare >/dev/null

mkdir -p "/lib/modules/$KERNEL_RELEASE"
ln -sfn "$KSRC" "/lib/modules/$KERNEL_RELEASE/build"
ln -sfn "$KSRC" "/lib/modules/$KERNEL_RELEASE/source"

echo "    building iomemory-vsl ($VSL_REPO @ $VSL_REF)"
git clone -q "$VSL_REPO" /build/iomemory-vsl
cd /build/iomemory-vsl
git checkout -q "$VSL_REF"
VSL_COMMIT=$(git rev-parse --short HEAD)
make module KERNELVER="$KERNEL_RELEASE" FIOARCH=x86_64 MODULE_VERSION="$VSL_COMMIT" \
  >/work/driver-build.log 2>&1 || { tail -60 /work/driver-build.log; exit 1; }

KO=$(find /build/iomemory-vsl/root/usr/src -maxdepth 2 -name iomemory-vsl.ko | head -1)
VERMAGIC=$(modinfo -F vermagic "$KO")
[[ "$VERMAGIC" == "$KERNEL_RELEASE "* ]] || { echo "ERROR: vermagic '$VERMAGIC' does not match" >&2; exit 1; }

NAME="iomemory-vsl-$KERNEL_RELEASE.ko"
cp "$KO" "/out/$NAME"
(cd /out && sha256sum "$NAME" > "$NAME.sha256")
cat > "/out/iomemory-vsl-$KERNEL_RELEASE.txt" <<EOF
unraid_version=$UNRAID_VERSION
kernel_release=$KERNEL_RELEASE
gcc_version=$GCC_VERSION
vsl_repo=$VSL_REPO
vsl_commit=$VSL_COMMIT
vermagic=$VERMAGIC
built=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF
echo "    built /out/$NAME"
