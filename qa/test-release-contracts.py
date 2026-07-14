from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
PLUGIN = ROOT / "jiuliu-usdt-payment"


def require(condition, message):
    if not condition:
        raise SystemExit("FAIL: " + message)


cron = (PLUGIN / "includes" / "class-jiuliu-usdt-cron.php").read_text(encoding="utf-8")
admin = (PLUGIN / "includes" / "class-jiuliu-usdt-admin.php").read_text(encoding="utf-8")
invoices = (PLUGIN / "includes" / "class-jiuliu-usdt-invoices.php").read_text(encoding="utf-8")
database = (PLUGIN / "includes" / "class-jiuliu-usdt-db.php").read_text(encoding="utf-8")
rate = (PLUGIN / "includes" / "class-jiuliu-usdt-rate.php").read_text(encoding="utf-8")
plugin_readme = (PLUGIN / "README.md").read_text(encoding="utf-8")
guide = (ROOT / "九流网络-USDT插件安装与运维说明.md").read_text(encoding="utf-8")

require("WP_REST_Server::CREATABLE" in cron, "Cron route is not registered as a POST-capable route")
require("WP_REST_Server::READABLE" not in cron, "Cron route still accepts GET")
require("get_header('x-jiuliu-cron-token')" in cron, "Cron permission check does not read the token header")
require("get_param('token')" not in cron, "Cron permission check still accepts a query token")
require("add_query_arg('token'" not in admin, "admin status still builds a token-bearing URL")
require("兼容查询参数" not in admin, "admin status still advertises query-token compatibility")

require("ENGINE=InnoDB" in database, "plugin financial tables are not explicitly created as InnoDB")
require("FOR UPDATE" in database, "database settlement guard does not acquire row locks")
require("begin_zibll_settlement_guard" in invoices, "business settlement path does not call the database guard")
require("commit_zibll_settlement_guard" in invoices, "business settlement path does not commit the guarded transaction")
require("zibll_settlement_uncertain" in invoices, "uncertain theme settlement is not protected from ordinary replay")

for phrase in ("实际到账", "手续费", "不得从"):
    require(phrase in invoices, f"cashier source is missing required fee/net-receipt phrase: {phrase}")

require("10 * MINUTE_IN_SECONDS" in rate, "automatic-rate freshness is no longer bounded at ten minutes")
require("2 * MINUTE_IN_SECONDS" in rate, "fallback-rate cache is no longer two minutes")
for document_name, document in (("plugin README", plugin_readme), ("operations guide", guide)):
    require("最长 10 分钟" in document, f"{document_name} does not document the real automatic-rate cache")
    require("缓存 2 分钟" in document or "缓存回退结果 2 分钟" in document, f"{document_name} does not document the fallback cache")
    require("15 分钟缓存" not in document, f"{document_name} still claims a 15-minute cache")

print("PASS: Header-only Cron, cashier fee wording and rate-cache documentation contracts")
