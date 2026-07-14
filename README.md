# 九流网络多链加密货币支付 2.0.0

面向 WordPress 子比（Zibll）主题的独立支付插件。插件将每个币种/网络路线注册为独立的子比支付方式，核验链上到账后调用子比统一订单完成入口。

## 支付路线

安装后提供 7 条默认关闭的主网路线：

| 币种 | 网络 | 查询方式 | 网络费币种 |
| --- | --- | --- | --- |
| USDT | TRON（TRC20） | TronGrid walletsolidity 固化块 | TRX |
| USDT | Ethereum（ERC20） | EVM JSON-RPC | ETH |
| USDC | Ethereum（ERC20） | EVM JSON-RPC | ETH |
| USDC | Base（ERC20） | EVM JSON-RPC | ETH |
| USDC | Arbitrum One（ERC20） | EVM JSON-RPC | ETH |
| USDC | Polygon PoS（ERC20） | EVM JSON-RPC | POL |
| USDC | Avalanche C-Chain（ERC20） | EVM JSON-RPC | AVAX |

管理员可以分别启用路线、填写公开收款地址及 CNY 汇率。TRON 路线使用 TronGrid walletsolidity 固化块判定，确认规则固定；EVM 路线必须配置支持 `eth_getLogs` 的 HTTPS JSON-RPC，并可分别设置确认数。

## 金额与手续费

收银台显示的是网站收款地址必须完整收到的精确金额。链上网络费、交易所提币费及平台费用全部由付款方另行承担，不得从页面金额中扣除。

## 安装

1. 在 WordPress 后台进入“插件 → 安装插件 → 上传插件”。
2. 上传 `jiuliu-crypto-payment-2.0.0.zip`，安装并启用。
3. 打开“多链收款 → 设置”，只启用需要的路线并填写对应网络的公开收款地址及只读查询服务。
4. 设置固定汇率；如选择 CoinGecko 市场参考汇率，仍需为每条路线填写备用固定汇率。
5. 打开“多链收款 → 系统状态”，测试全部已启用路线和汇率。
6. 配置服务器 Cron，并使用小额真实主网支付完成全流程验收。

不要向插件填写私钥、助记词、钱包密码、交易所密码或 WordPress 管理员密码。

## 自动监控

插件注册每分钟一次的 WP-Cron 作为兜底。低访问量站点建议让服务器每分钟调用一次本站接口：

```cron
* * * * * curl -fsS -X POST -H "X-Jiuliu-Cron-Token: 后台生成的随机密钥" "https://你的域名/wp-json/jiuliu-crypto/v1/cron" >/dev/null 2>&1
```

接口只接受 `POST`，密钥只接受 `X-Jiuliu-Cron-Token` 请求头；还可以设置来源 IP 白名单。后台“系统状态”会按当前域名生成可直接使用的命令。

## 子比订单关闭

“子比订单关闭后继续观察到账”默认开启。关闭后的支付单在观察期内检测到到账时只会进入人工处理，绝不会自动发货。管理员也可以关闭该开关以减少 API 查询；此时可能无法自动发现用户转账后又关闭订单的情况，但仍可在后台提交交易哈希核验。

链上监控是只读查询，不会发起交易，也不会消耗 TRX Energy 或其他链上 Gas；它消耗的是节点/API 查询额度和服务器资源。

## 汇率说明

固定模式直接使用管理员设置的 CNY 汇率。自动模式使用 CoinGecko 的第三方市场参考数据，并校验更新时间、相对备用汇率的偏差及返回范围；接口失败或触发偏差熔断时按路线回退固定汇率。CoinGecko 不是 Tether 或 Circle 的官方结算汇率，也不能保证公共接口始终可用。

## 发布文件

- 插件源码：`jiuliu-crypto-payment/`
- 安装包：`dist/jiuliu-crypto-payment-2.0.0.zip`
- 校验文件：`SHA256SUMS`
- 自动化检查：`qa/`
- 确定性构建：`scripts/build_release.py`

## 本地 QA

```bash
find jiuliu-crypto-payment qa -type f -name '*.php' -print0 | xargs -0 -n1 php -l
for test in qa/test-*.php; do php "$test"; done
python qa/test-utf8.py
python qa/test-release-metadata.py
python scripts/build_release.py --output dist/jiuliu-crypto-payment-2.0.0.zip
python qa/test-zip-structure.py dist/jiuliu-crypto-payment-2.0.0.zip
```
