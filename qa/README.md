# QA 说明

本目录的测试始终读取同一个仓库内的 `../jiuliu-usdt-payment/`，避免测试一份源码却发布另一份源码。

## 本地检查

1. 对 `jiuliu-usdt-payment/` 和 `qa/` 下所有 PHP 文件执行 `php -l`。
2. 依次执行 `qa/test-*.php`。
3. 执行下列静态/发布检查：

       python qa/test-frontend-parse.py
       python qa/test-utf8.py
       python qa/test-release-metadata.py
       python qa/test-release-contracts.py

4. 需要验证安装包时，必须先从当前源码重新构建，然后把该正式 ZIP 的路径显式传入测试：

       python scripts/build_release.py --output dist/jiuliu-usdt-payment-1.0.2.zip
       python qa/test-zip-structure.py dist/jiuliu-usdt-payment-1.0.2.zip

`test-zip-structure.py` 不提供默认的旧 QA 包；它会同时检查 ZIP 名称、目录结构、CRC、文件集和每个文件的字节内容。

GitHub Actions 在插件声明支持的 PHP 7.0、7.1，以及 PHP 8.2、8.3 上重复执行上述检查。

`test-mysql-lock-interleaving.php` 是真实双连接数据库测试。本地未设置 `QA_MYSQL_HOST` 时会明确跳过；GitHub Actions 会启动 MariaDB 10.11，验证结算连接持有 `FOR UPDATE` 锁时，关闭订单连接必须等待，且提交或回滚后状态不会混合。
