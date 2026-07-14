from pathlib import Path
import re


ROOT = Path(__file__).resolve().parent.parent
PLUGIN = ROOT / "jiuliu-usdt-payment"
EXPECTED_VERSION = "1.0.2"


def require(condition, message):
    if not condition:
        raise SystemExit("FAIL: " + message)


main = (PLUGIN / "jiuliu-usdt-payment.php").read_text(encoding="utf-8")
header = re.search(r"^\s*\* Version:\s*([^\s]+)\s*$", main, re.MULTILINE)
constant = re.search(r"define\('JIULIU_USDT_VERSION',\s*'([^']+)'\);", main)
db_constant = re.search(r"define\('JIULIU_USDT_DB_VERSION',\s*'([^']+)'\);", main)
require(header is not None, "plugin Version header is missing")
require(constant is not None, "JIULIU_USDT_VERSION is missing")
require(header.group(1) == EXPECTED_VERSION, "plugin Version header is not 1.0.2")
require(constant.group(1) == EXPECTED_VERSION, "JIULIU_USDT_VERSION is not 1.0.2")
require(db_constant is not None and db_constant.group(1) == "1.0.3", "database schema version is not 1.0.3")

wp_readme = (PLUGIN / "readme.txt").read_text(encoding="utf-8")
stable = re.search(r"^Stable tag:\s*(\S+)\s*$", wp_readme, re.MULTILINE)
changelog = re.search(r"^== Changelog ==\s*\n+\s*= ([^=]+) =", wp_readme, re.MULTILINE)
require(stable is not None and stable.group(1) == EXPECTED_VERSION, "Stable tag is not 1.0.2")
require(
    changelog is not None and changelog.group(1).strip() == EXPECTED_VERSION,
    "latest readme changelog entry is not 1.0.2",
)

plugin_readme = (PLUGIN / "README.md").read_text(encoding="utf-8")
guide = (ROOT / "九流网络-USDT插件安装与运维说明.md").read_text(encoding="utf-8")
root_readme = (ROOT / "README.md").read_text(encoding="utf-8")

require("1.0.0" not in plugin_readme and "1.0.1" not in plugin_readme, "plugin README contains a stale current-version reference")
require("1.0.0" not in guide and "1.0.1" not in guide, "operations guide contains a stale current-version reference")
require("jiuliu-usdt-payment-1.0.2.zip" in guide, "operations guide does not name the 1.0.2 ZIP")
require("jiuliu-usdt-payment-1.0.2.zip" in root_readme, "root README does not name the 1.0.2 ZIP")

print("PASS: plugin header, stable tag, changelog and release documentation are 1.0.2")
