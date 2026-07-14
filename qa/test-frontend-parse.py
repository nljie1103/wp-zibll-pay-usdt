from pathlib import Path
import os
import shutil
import subprocess
import sys

HERE = Path(__file__).resolve().parent
source_path = HERE.parent / "jiuliu-usdt-payment" / "assets" / "js" / "frontend.js"
source = source_path.read_text(encoding="utf-8")

node = os.environ.get("NODE_BINARY") or shutil.which("node")
if node and not Path(node).is_file():
    raise SystemExit(f"FAIL: NODE_BINARY does not point to a file: {node}")
if not node:
    if os.environ.get("CI"):
        raise SystemExit("FAIL: Node.js is required for the frontend syntax check in CI")
    print("SKIP: Node.js is not available locally; CI still enforces node --check")
    raise SystemExit(0)

result = subprocess.run(
    [node, "--check", str(source_path)],
    capture_output=True,
    text=True,
    encoding="utf-8",
)
if result.returncode:
    sys.stderr.write(result.stdout)
    sys.stderr.write(result.stderr)
    raise SystemExit("FAIL: frontend.js did not pass node --check")

print(
    "PASS: frontend.js passed node --check; "
    f"bytes={len(source.encode('utf-8'))}"
)
