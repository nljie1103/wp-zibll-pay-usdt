# 2.1.0 QA

所有检查直接读取同一仓库中的 `../jiuliu-crypto-payment/`，构建脚本也只打包这份源码。

## 本地检查

PHP 语法：

```bash
find jiuliu-crypto-payment qa -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

2.1.0 功能契约：

```bash
for test in qa/test-*.php; do php "$test"; done
```

通用检查：

```bash
python qa/test-utf8.py
python qa/test-release-metadata.py
```

确定性安装包：

```bash
python scripts/build_release.py --output dist/jiuliu-crypto-payment-2.1.0.zip
python qa/test-zip-structure.py dist/jiuliu-crypto-payment-2.1.0.zip
```

连续构建两次得到的 ZIP 必须逐字节一致。`test-zip-structure.py` 会检查文件名、单一插件根目录、路径安全、CRC、文件集合、规范化内容及版本号。

`test-mysql-lock-interleaving.php` 是可选的真实 MariaDB 双连接锁测试。未设置 `QA_MYSQL_HOST` 时本地会跳过；GitHub Actions 会启动 MariaDB 10.11 执行。
