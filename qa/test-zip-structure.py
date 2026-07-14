from pathlib import Path, PurePosixPath
import re
import stat
import sys
import zipfile


HERE = Path(__file__).resolve().parent
SOURCE = HERE.parent / "jiuliu-crypto-payment"
ROOT = "jiuliu-crypto-payment"
MAIN_FILE = "jiuliu-crypto-payment.php"
FIXED_TIMESTAMP = (1980, 1, 1, 0, 0, 0)
TEXT_SUFFIXES = {
    ".css", ".html", ".js", ".json", ".md", ".php", ".po", ".pot",
    ".svg", ".txt", ".xml", ".yml", ".yaml",
}


def canonical_source_bytes(path):
    payload = path.read_bytes()
    if path.suffix.lower() in TEXT_SUFFIXES:
        payload = payload.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
    return payload


if len(sys.argv) != 2:
    raise SystemExit(
        "USAGE: python qa/test-zip-structure.py "
        "<freshly-built-jiuliu-crypto-payment-version.zip>"
    )

zip_path = Path(sys.argv[1]).resolve()
if not zip_path.is_file():
    raise SystemExit(f"FAIL: ZIP does not exist: {zip_path}")

expected_files = {
    f"{ROOT}/{path.relative_to(SOURCE).as_posix()}"
    for path in SOURCE.rglob("*")
    if path.is_file()
}

main_source = (SOURCE / MAIN_FILE).read_text(encoding="utf-8")
version_match = re.search(r"define\('JIULIU_CRYPTO_VERSION',\s*'([^']+)'\);", main_source)
if not version_match:
    raise SystemExit("FAIL: unable to read JIULIU_CRYPTO_VERSION from plugin source")
version = version_match.group(1)
expected_filename = f"jiuliu-crypto-payment-{version}.zip"
if zip_path.name != expected_filename:
    raise SystemExit(
        f"FAIL: release ZIP name is {zip_path.name!r}; expected {expected_filename!r}"
    )

with zipfile.ZipFile(zip_path) as archive:
    raw_names = archive.namelist()
    names = [name.replace("\\", "/") for name in raw_names]
    file_names = {name for name in names if not name.endswith("/")}

    if archive.testzip() is not None:
        raise SystemExit("FAIL: ZIP CRC validation failed")
    if len(names) != len(set(names)):
        raise SystemExit("FAIL: ZIP contains duplicate entry names")

    for info, name in zip(archive.infolist(), names):
        pure = PurePosixPath(name)
        if pure.is_absolute() or ".." in pure.parts:
            raise SystemExit(f"FAIL: unsafe ZIP entry: {name}")
        if not name.startswith(ROOT + "/"):
            raise SystemExit(f"FAIL: entry is outside the single plugin root: {name}")
        if info.date_time != FIXED_TIMESTAMP:
            raise SystemExit(f"FAIL: non-deterministic timestamp for {name}")
        if info.compress_type != zipfile.ZIP_STORED:
            raise SystemExit(f"FAIL: non-deterministic compression for {name}")
        mode = (info.external_attr >> 16) & 0xFFFF
        if name in file_names and not stat.S_ISREG(mode):
            raise SystemExit(f"FAIL: archive entry is not a regular file: {name}")

    main = f"{ROOT}/{MAIN_FILE}"
    if main not in file_names:
        raise SystemExit("FAIL: plugin main file is not at the expected install path")

    missing = sorted(expected_files - file_names)
    unexpected = sorted(file_names - expected_files)
    if missing:
        raise SystemExit("FAIL: ZIP is missing source files: " + ", ".join(missing))
    if unexpected:
        raise SystemExit("FAIL: ZIP contains unexpected files: " + ", ".join(unexpected))

    mismatches = []
    for archive_name in sorted(expected_files):
        relative = PurePosixPath(archive_name).relative_to(ROOT)
        source_path = SOURCE / Path(*relative.parts)
        if archive.read(archive_name) != canonical_source_bytes(source_path):
            mismatches.append(archive_name)
    if mismatches:
        raise SystemExit(
            "FAIL: ZIP content differs from canonical source: " + ", ".join(mismatches)
        )

print(
    "PASS: deterministic ZIP root/CRC/path/file-set/content/version validation; "
    f"files={len(file_names)}, bytes={zip_path.stat().st_size}"
)
