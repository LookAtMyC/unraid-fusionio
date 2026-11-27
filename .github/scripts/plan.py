"""Decide which Unraid kernels still need a driver build.

Reads Unraid's public release list, finds the kernel each release ships
(from its update plugin), and prints a GitHub Actions matrix of kernels
that have no release in this repository yet.

Env:
  REQUESTED   optional, space separated Unraid versions to build (forces them)
  MIN_VERSION oldest Unraid version to consider (default 6.12.0)
  EXISTING    newline separated release tags that already exist
"""
import json
import os
import re
import sys
import urllib.request


def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": "unraid-fusionio-ci"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read().decode("utf-8", "replace")


def vtuple(v):
    return tuple(int(x) for x in re.findall(r"\d+", v)[:3])


releases = json.loads(get("https://releases.unraid.net/json"))
requested = os.environ.get("REQUESTED", "").split()
min_version = vtuple(os.environ.get("MIN_VERSION", "6.12.0"))
existing = set(os.environ.get("EXISTING", "").split())

builds = {}  # kernel -> unraid version (newest release using that kernel)
for r in sorted(releases, key=lambda r: vtuple(r["version"]), reverse=True):
    v = r["version"]
    if requested and v not in requested:
        continue
    if not requested and vtuple(v) < min_version:
        continue
    try:
        plg = get(r["plugin_url"])
    except Exception as e:  # noqa: BLE001
        print(f"skip {v}: cannot read plugin ({e})", file=sys.stderr)
        continue
    m = re.search(r'<!ENTITY\s+kernel\s+"([^"]+)"', plg)
    if not m:
        print(f"skip {v}: no kernel entity", file=sys.stderr)
        continue
    kernel = m.group(1)
    if kernel in builds:
        continue
    if kernel in existing and not requested:
        continue
    builds[kernel] = v

matrix = [{"unraid": v, "kernel": k} for k, v in builds.items()]
print(f"to build: {matrix}", file=sys.stderr)
print(json.dumps({"include": matrix}))
