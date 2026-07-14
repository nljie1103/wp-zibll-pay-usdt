from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
TEXT_SUFFIXES = {".php", ".js", ".css", ".svg", ".md", ".txt", ".py", ".yml", ".yaml"}
TEXT_NAMES = {".gitignore", "LICENSE"}
SKIP_PARTS = {".git", "dist", "__pycache__", "python-deps"}

checked = 0
for path in sorted(ROOT.rglob("*")):
    if not path.is_file() or any(part in SKIP_PARTS for part in path.parts):
        continue
    if path.suffix.lower() not in TEXT_SUFFIXES and path.name not in TEXT_NAMES:
        continue

    data = path.read_bytes()
    try:
        text = data.decode("utf-8", errors="strict")
    except UnicodeDecodeError as error:
        raise SystemExit(f"FAIL: {path.relative_to(ROOT)} is not valid UTF-8: {error}")

    if "\ufffd" in text:
        raise SystemExit(f"FAIL: {path.relative_to(ROOT)} contains a replacement character")
    if path.suffix.lower() == ".php" and data.startswith(b"\xef\xbb\xbf"):
        raise SystemExit(f"FAIL: {path.relative_to(ROOT)} has a PHP-breaking UTF-8 BOM")
    checked += 1

if not checked:
    raise SystemExit("FAIL: UTF-8 test did not inspect any files")

print(f"PASS: strict UTF-8 validation; files={checked}")
