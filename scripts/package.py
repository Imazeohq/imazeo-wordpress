"""Stage and verify an installable Imazeo WordPress plugin ZIP."""

import argparse
import hashlib
import re
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    "imazeo.php",
    "uninstall.php",
    "readme.txt",
    "LICENSE",
    "assets/media-picker.js",
    "includes/class-admin.php",
    "includes/class-api.php",
    "includes/class-connection.php",
    "includes/class-featured-media.php",
    "includes/class-lifecycle.php",
    "includes/class-media.php",
    "includes/class-operations.php",
    "includes/class-processing.php",
    "includes/class-project-library.php",
    "includes/class-queue.php",
    "includes/class-worker.php",
    "includes/class-workspace.php",
    "includes/operations.json",
    "languages/imazeo.pot",
)


def package(tag: str | None) -> Path:
    header = (ROOT / "imazeo.php").read_text(encoding="utf-8")
    readme = (ROOT / "readme.txt").read_text(encoding="utf-8")
    version = re.search(r"^ \* Version: (\d+\.\d+\.\d+)$", header, re.MULTILINE)
    stable = re.search(r"^Stable tag: (\d+\.\d+\.\d+)$", readme, re.MULTILINE)
    if not version or not stable or version[1] != stable[1]:
        raise ValueError("The plugin version and stable tag must agree.")
    if tag is not None and tag != f"v{version[1]}":
        raise ValueError("The release ref must match the plugin version.")

    build = ROOT / "build"
    staging = build / "imazeo"
    contents = {}
    for relative in FILES:
        source = ROOT / relative
        if not source.is_file() or source.is_symlink():
            raise ValueError(f"Invalid distribution file {relative}")
        contents[relative] = source.read_bytes()
    existing = {path.relative_to(staging).as_posix() for path in staging.rglob("*") if path.is_file() or path.is_symlink()}
    if existing - set(FILES) or any(path.is_symlink() for path in staging.rglob("*")):
        raise ValueError("The staging folder contains unexpected entries.")

    for relative, data in contents.items():
        destination = staging / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(data)

    archive = build / f"imazeo-{version[1]}.zip"
    with ZipFile(archive, "w") as plugin:
        for relative, data in sorted(contents.items()):
            entry = ZipInfo(f"imazeo/{relative}")
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            plugin.writestr(entry, data, compress_type=ZIP_DEFLATED, compresslevel=9)

    with ZipFile(archive) as plugin:
        if plugin.testzip() is not None or plugin.namelist() != sorted(f"imazeo/{name}" for name in FILES):
            raise ValueError("The ZIP failed to match the distribution allowlist.")
        if any(plugin.read(f"imazeo/{name}") != data for name, data in contents.items()):
            raise ValueError("The archive content differs from the staged plugin.")
    checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
    (build / "SHA256SUMS").write_text(f"{checksum}  {archive.name}\n", encoding="ascii")
    return archive


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", help="Require a matching Git release tag.")
    print(package(parser.parse_args().tag))
