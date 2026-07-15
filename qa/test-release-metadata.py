from pathlib import Path
import re


ROOT = Path(__file__).resolve().parent.parent
PLUGIN = ROOT / "jiuliu-crypto-payment"
EXPECTED_VERSION = "2.1.3"
EXPECTED_ZIP = f"jiuliu-crypto-payment-{EXPECTED_VERSION}.zip"


def require(condition, message):
    if not condition:
        raise SystemExit("FAIL: " + message)


main = (PLUGIN / "jiuliu-crypto-payment.php").read_text(encoding="utf-8")
header = re.search(r"^\s*\* Version:\s*([^\s]+)\s*$", main, re.MULTILINE)
constant = re.search(r"define\('JIULIU_CRYPTO_VERSION',\s*'([^']+)'\);", main)
require(header is not None, "plugin Version header is missing")
require(constant is not None, "JIULIU_CRYPTO_VERSION is missing")
require(header.group(1) == EXPECTED_VERSION, "plugin Version header is not 2.1.3")
require(constant.group(1) == EXPECTED_VERSION, "JIULIU_CRYPTO_VERSION is not 2.1.3")
require("JIULIU_CRYPTO_DB_VERSION" not in main, "entrypoint contains a database upgrade version")

wp_readme = (PLUGIN / "readme.txt").read_text(encoding="utf-8")
stable = re.search(r"^Stable tag:\s*(\S+)\s*$", wp_readme, re.MULTILINE)
changelog = re.search(r"^== Changelog ==\s*\n+\s*= ([^=]+) =", wp_readme, re.MULTILINE)
require(stable is not None and stable.group(1) == EXPECTED_VERSION, "Stable tag is not 2.1.3")
require(
    changelog is not None and changelog.group(1).strip() == EXPECTED_VERSION,
    "latest readme changelog entry is not 2.1.3",
)

plugin_readme = (PLUGIN / "README.md").read_text(encoding="utf-8")
root_readme = (ROOT / "README.md").read_text(encoding="utf-8")
build_script = (ROOT / "scripts" / "build_release.py").read_text(encoding="utf-8")
sums = (ROOT / "SHA256SUMS").read_text(encoding="ascii").strip()

for label, document in (("plugin README", plugin_readme), ("root README", root_readme)):
    require(EXPECTED_ZIP in document, f"{label} does not name the 2.1.3 ZIP")
    require("网站收款地址必须完整收到" in document, f"{label} omits exact receipt wording")
    require("付款方" in document and "手续费" in document, f"{label} omits payer fee wording")

require('SOURCE = ROOT / "jiuliu-crypto-payment"' in build_script, "build source directory is wrong")
require('ARCHIVE_ROOT = "jiuliu-crypto-payment"' in build_script, "archive root directory is wrong")
require(
    re.fullmatch(r"[0-9A-F]{64}  dist/" + re.escape(EXPECTED_ZIP), sums) is not None,
    "SHA256SUMS does not contain the canonical 2.1.3 dist artifact",
)
require(not sums.startswith("0" * 64), "SHA256SUMS still contains the pre-release placeholder")

print("PASS: plugin header, stable tag, changelog, docs, build metadata and checksum target are 2.1.3")
