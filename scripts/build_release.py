from argparse import ArgumentParser
from pathlib import Path, PurePosixPath
import hashlib
import os
import re
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "jiuliu-crypto-payment"
MAIN_FILE = SOURCE / "jiuliu-crypto-payment.php"
ARCHIVE_ROOT = "jiuliu-crypto-payment"
FIXED_TIMESTAMP = (1980, 1, 1, 0, 0, 0)
TEXT_SUFFIXES = {
    ".css", ".html", ".js", ".json", ".md", ".php", ".po", ".pot",
    ".svg", ".txt", ".xml", ".yml", ".yaml",
}


def plugin_version():
    main = MAIN_FILE.read_text(encoding="utf-8")
    match = re.search(r"define\('JIULIU_CRYPTO_VERSION',\s*'([^']+)'\);", main)
    if not match or not re.fullmatch(r"\d+\.\d+\.\d+", match.group(1)):
        raise SystemExit("Unable to read a semantic JIULIU_CRYPTO_VERSION")
    return match.group(1)


def source_files():
    files = []
    for path in SOURCE.rglob("*"):
        if path.is_symlink():
            raise SystemExit(f"Refusing to package symlink: {path.relative_to(ROOT)}")
        if path.is_file():
            files.append(path)
    return sorted(files, key=lambda path: path.relative_to(SOURCE).as_posix())


def release_bytes(path):
    """Return checkout-independent bytes for reproducible Windows/Linux ZIPs."""
    payload = path.read_bytes()
    if path.suffix.lower() in TEXT_SUFFIXES:
        payload = payload.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
    return payload


parser = ArgumentParser(description="Build a deterministic Jiuliu multi-chain payment plugin ZIP")
parser.add_argument(
    "--output",
    type=Path,
    help="output ZIP path; defaults to dist/jiuliu-crypto-payment-VERSION.zip",
)
args = parser.parse_args()

version = plugin_version()
expected_name = f"jiuliu-crypto-payment-{version}.zip"
output = args.output.resolve() if args.output else (ROOT / "dist" / expected_name)
if output.name != expected_name:
    raise SystemExit(f"Output filename must be {expected_name}")
if SOURCE == output.parent or SOURCE in output.parents:
    raise SystemExit("Release ZIP must not be written inside the plugin source directory")

output.parent.mkdir(parents=True, exist_ok=True)
descriptor, temporary_name = tempfile.mkstemp(
    prefix=expected_name + ".",
    suffix=".tmp",
    dir=str(output.parent),
)
os.close(descriptor)
temporary = Path(temporary_name)

try:
    # ZIP_STORED avoids zlib-version-dependent streams. Fixed timestamps,
    # permissions, order and LF text bytes make the archive reproducible.
    with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_STORED) as archive:
        for path in source_files():
            relative = path.relative_to(SOURCE).as_posix()
            archive_name = str(PurePosixPath(ARCHIVE_ROOT) / relative)
            info = zipfile.ZipInfo(archive_name, FIXED_TIMESTAMP)
            info.compress_type = zipfile.ZIP_STORED
            info.create_system = 3
            info.external_attr = (0o100644 & 0xFFFF) << 16
            archive.writestr(info, release_bytes(path), compress_type=zipfile.ZIP_STORED)
    temporary.replace(output)
finally:
    if temporary.exists():
        temporary.unlink()

digest = hashlib.sha256(output.read_bytes()).hexdigest().upper()
print(f"Built {output} ({output.stat().st_size} bytes)")
print(f"SHA-256: {digest}")
