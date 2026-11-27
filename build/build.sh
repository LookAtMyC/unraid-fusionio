#!/usr/bin/env bash
# Build the Fusion-io iomemory-vsl (VSL3) driver for one Unraid release.
#
# Usage:  build/build.sh <unraid-version> [out-dir]
#   e.g.  build/build.sh 7.1.4 out
#
# Needs: bash, docker. Everything else runs inside containers.
#
# Optional environment:
#   UNRAID_ZIP  path to an already downloaded unRAIDServer-<ver>-x86_64.zip
#   VSL_REPO    driver source repo   (default: RemixVSL/iomemory-vsl)
#   VSL_REF     driver branch/commit (default: main)
#   WORK        working directory    (default: a new temp dir)
set -euo pipefail

VER="${1:?usage: build/build.sh <unraid-version> [out-dir]}"
OUT_ARG="${2:-out}"

# Absolute directory path as docker on this host understands it
# (Git Bash on Windows needs C:/... rather than /c/...).
absdir() { (cd "$1" && { pwd -W 2>/dev/null || pwd; }); }

HERE="$(absdir "$(dirname "$0")")"
mkdir -p "$OUT_ARG"
OUT="$(absdir "$OUT_ARG")"
WORK="${WORK:-$(mktemp -d)}"
mkdir -p "$WORK"
WORK="$(absdir "$WORK")"
export VSL_REPO="${VSL_REPO:-https://github.com/RemixVSL/iomemory-vsl.git}"
export VSL_REF="${VSL_REF:-main}"

# Git Bash on Windows rewrites /paths passed to docker; turn that off.
export MSYS_NO_PATHCONV=1

ZIP_MOUNT=()
if [[ -n "${UNRAID_ZIP:-}" ]]; then
  ZIP_DIR="$(absdir "$(dirname "$UNRAID_ZIP")")"
  ZIP_MOUNT=(-v "$ZIP_DIR:/zip:ro" -e "UNRAID_ZIP=/zip/$(basename "$UNRAID_ZIP")")
fi

echo "==> [1/2] Extracting kernel config for Unraid $VER"
docker run --rm \
  -v "$HERE:/scripts:ro" -v "$WORK:/work" "${ZIP_MOUNT[@]}" \
  -e "UNRAID_VERSION=$VER" \
  debian:bookworm bash /scripts/extract.sh

# shellcheck disable=SC1091
source "$WORK/kernel.env"
echo "    kernel $KERNEL_RELEASE, built with gcc $GCC_VERSION"

echo "==> [2/2] Compiling driver in gcc:$GCC_VERSION"
docker run --rm \
  -v "$HERE:/scripts:ro" -v "$WORK:/work" -v "$OUT:/out" \
  -e VSL_REPO -e VSL_REF -e "UNRAID_VERSION=$VER" \
  "gcc:$GCC_VERSION" bash /scripts/compile.sh

echo "==> Done:"
ls -la "$OUT"
