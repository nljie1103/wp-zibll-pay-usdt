from pathlib import Path, PurePosixPath
import re
import sys
import zipfile

HERE = Path(__file__).resolve().parent
SOURCE = HERE.parent / "jiuliu-usdt-payment"
ROOT = "jiuliu-usdt-payment"

if len(sys.argv) != 2:
    raise SystemExit(
        "USAGE: python qa/test-zip-structure.py "
        "<freshly-built-jiuliu-usdt-payment-version.zip>"
    )

ZIP_PATH = Path(sys.argv[1]).resolve()

if not ZIP_PATH.is_file():
    raise SystemExit(f"FAIL: ZIP does not exist: {ZIP_PATH}")

expected_files = {
    f"{ROOT}/{path.relative_to(SOURCE).as_posix()}"
    for path in SOURCE.rglob("*")
    if path.is_file()
}

main_source = (SOURCE / "jiuliu-usdt-payment.php").read_text(encoding="utf-8")
version_match = re.search(r"define\('JIULIU_USDT_VERSION',\s*'([^']+)'\);", main_source)
if not version_match:
    raise SystemExit("FAIL: unable to read JIULIU_USDT_VERSION from plugin source")
version = version_match.group(1)
expected_filename = f"jiuliu-usdt-payment-{version}.zip"
if ZIP_PATH.name != expected_filename:
    raise SystemExit(
        f"FAIL: release ZIP name is {ZIP_PATH.name!r}; expected {expected_filename!r}"
    )

with zipfile.ZipFile(ZIP_PATH) as archive:
    raw_names = archive.namelist()
    names = [name.replace("\\", "/") for name in raw_names]
    file_names = {name for name in names if not name.endswith("/")}

    if archive.testzip() is not None:
        raise SystemExit("FAIL: ZIP CRC validation failed")
    if len(names) != len(set(names)):
        raise SystemExit("FAIL: ZIP contains duplicate entry names")
    for name in names:
        pure = PurePosixPath(name)
        if pure.is_absolute() or ".." in pure.parts:
            raise SystemExit(f"FAIL: unsafe ZIP entry: {name}")
        if not name.startswith(ROOT + "/"):
            raise SystemExit(f"FAIL: entry is outside the single plugin root: {name}")

    main = f"{ROOT}/jiuliu-usdt-payment.php"
    if main not in file_names:
        raise SystemExit("FAIL: plugin main file is not at the expected install path")
    missing = sorted(expected_files - file_names)
    unexpected = sorted(file_names - expected_files)
    if missing:
        raise SystemExit("FAIL: ZIP is missing source files: " + ", ".join(missing))
    if unexpected:
        raise SystemExit("FAIL: ZIP contains unexpected files: " + ", ".join(unexpected))

    content_mismatches = []
    for archive_name in sorted(expected_files):
        relative = PurePosixPath(archive_name).relative_to(ROOT)
        source_bytes = (SOURCE / Path(*relative.parts)).read_bytes()
        if archive.read(archive_name) != source_bytes:
            content_mismatches.append(archive_name)
    if content_mismatches:
        raise SystemExit(
            "FAIL: ZIP content differs from canonical source: "
            + ", ".join(content_mismatches)
        )

print(
    f"PASS: ZIP root/CRC/path/file-set/content/version validation; "
    f"files={len(file_names)}, bytes={ZIP_PATH.stat().st_size}"
)
