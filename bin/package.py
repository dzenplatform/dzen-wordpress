#!/usr/bin/env python3
"""Build the installable WordPress ZIP from tracked runtime files only."""

import argparse
import hashlib
from pathlib import Path
import re
import subprocess
import sys
import zipfile


ROOT = Path(__file__).resolve().parent.parent
RUNTIME_PATHS = ("dzen-chat.php", "uninstall.php", "readme.txt", "src", "assets")


def metadata():
    plugin = (ROOT / "dzen-chat.php").read_text()
    readme = (ROOT / "readme.txt").read_text()
    versions = []
    for text, pattern, label in (
        (plugin, r"^ \* Version: (.+)$", "plugin header"),
        (plugin, r"^define\('DZEN_CHAT_VERSION', '([^']+)'\);$", "version constant"),
        (readme, r"^Stable tag: (.+)$", "readme stable tag"),
    ):
        matches = re.findall(pattern, text, re.MULTILINE)
        if len(matches) != 1:
            raise ValueError(f"Expected exactly one {label}")
        versions.append(matches[0])
    version = versions[0]
    if not re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", version):
        raise ValueError("Version must be X.Y.Z without a leading v or leading zeroes")
    if len(set(versions)) != 1:
        raise ValueError("Plugin header, version constant and readme stable tag must match")
    changelog = readme.split("== Changelog ==", 1)[-1]
    entry = re.search(r"^= " + re.escape(version) + r" =\s*\n(.*?)(?=^= |\Z)", changelog, re.MULTILINE | re.DOTALL)
    if "== Changelog ==" not in readme or not entry or not entry[1].strip():
        raise ValueError(f"Missing changelog for {version}")
    return version, entry[1].strip()


def build(tag=None, version_only=False):
    version, changes = metadata()
    if tag is not None and tag != f"v{version}":
        raise ValueError(f"Release tag must be v{version}; got {tag!r}")
    if version_only:
        print(version)
        return
    tracked = subprocess.check_output(
        ["git", "ls-files", "-z", "--", *RUNTIME_PATHS], cwd=ROOT
    ).decode().split("\0")
    files = sorted(path for path in tracked if path)
    if not set(RUNTIME_PATHS[:3]).issubset(files) or not any(p.startswith("src/") for p in files):
        raise ValueError("Required runtime files are missing from the Git index")
    for name in files:
        path = ROOT / name
        if path.is_symlink() or not path.is_file() or ROOT not in path.resolve().parents:
            raise ValueError(f"Runtime file must be a regular file inside the repository: {name}")
    dist = ROOT / "dist"
    dist.mkdir(exist_ok=True)
    archive = dist / f"dzen-chat-{version}.zip"
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as package:
        for name in files:
            info = zipfile.ZipInfo(f"dzen-chat/{name}", date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(info, (ROOT / name).read_bytes())
    checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix(".zip.sha256").write_text(f"{checksum}  {archive.name}\n")
    (dist / "release-notes.md").write_text(
        f"{changes}\n\n"
        f"Install `dzen-chat-{version}.zip` through **Plugins → Add New → Upload Plugin**.\n\n"
        "The ZIP contains the installable plugin. GitHub's automatic source archives include "
        "development files and are not the installation package.\n\n"
        "GitHub Releases do not enable updates inside WordPress automatically.\n"
    )
    print(archive.relative_to(ROOT))
    print(archive.with_suffix(".zip.sha256").relative_to(ROOT))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", help="Require an exact vX.Y.Z release tag")
    parser.add_argument("--version", action="store_true", help="Validate metadata and print the version only")
    args = parser.parse_args()
    try:
        build(args.tag, args.version)
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        print(f"Package failed: {error}", file=sys.stderr)
        sys.exit(1)
