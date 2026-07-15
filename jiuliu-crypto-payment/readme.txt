=== 九流网络多链加密货币支付 ===
Contributors: jiuliu
Tags: usdt, usdc, fdusd, pyusd, eurc, trc20, bep20, erc20, zibll
Requires at least: 6.0
Tested up to: 7.0.1
Requires PHP: 7.0
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

为子比（Zibll）主题提供多币种、多链收银台、严格链上核验和原生订单交付。

== Description ==

插件针对子比 V9 支付接口开发，不修改主题文件。

主要功能：

* 36 条默认关闭的主网路线，覆盖 25 个网络
* USDT：TRON、Ethereum、BNB Smart Chain、Celo、Avalanche、Kava EVM、Kaia
* USDC：22 条路线，包括 Base、Arbitrum、Polygon、Optimism、Celo、Linea、ZKsync、Sonic、Cronos、HyperEVM、Monad、Sei、XDC 等
* FDUSD：BNB Smart Chain；PYUSD：Ethereum、Arbitrum；EURC：Ethereum、Avalanche、Base、Cronos
* 前台将已启用路线折叠成“币种 → 网络”两级选择器，用户可自由选择
* TronGrid 与 EVM JSON-RPC 只读链上核验
* 支持 6/18 位代币合约精度，前台最多显示 6 位，链上原始整数全精度匹配
* 合约、收款地址、链 ID、固化/确认状态、付款时间及交易哈希唯一性校验
* 支持余额、会员、付费内容、资源、商城及论坛付费场景
* 前台二维码、复制地址、复制金额、倒计时和交易哈希核验
* 后台支付单、人工核验、日志、路线测试和汇率测试
* 每分钟 WP-Cron 及带请求头密钥、可选 IP 白名单的服务器 Cron 接口
* 子比订单关闭后继续观察到账的独立开关
* 固定汇率或 CoinGecko 第三方市场参考汇率
* 不保存钱包私钥、助记词或钱包密码

收银台显示的是网站收款地址必须完整收到的精确金额。链上网络费及交易所提币手续费由付款方另行承担，不得从页面金额中扣除。

== Installation ==

1. 在 WordPress 后台上传并启用插件。
2. 打开“多链收款 → 设置”。
3. 为需要的路线填写对应网络的公开收款地址。
4. 为 TRON 配置 TronGrid；为 EVM 路线配置支持 eth_getLogs 的 HTTPS JSON-RPC。
5. 设置每条路线的 CNY 固定/备用汇率，并为 EVM 路线设置确认数；TRON 使用固定的 TronGrid walletsolidity 固化块规则。
6. 在“系统状态”测试全部已启用路线和汇率。
7. 配置每分钟服务器 Cron，保留 WP-Cron 作为兜底。
8. 使用小额真实主网订单完成全流程验收后开启收款总开关。

不要填写私钥、助记词、钱包密码、交易所密码或 WordPress 管理员密码。

== Frequently Asked Questions ==

= 支持哪些币种和网络？ =

提供 36 条主网路线，覆盖 USDT、USDC、FDUSD、PYUSD、EURC 和 25 个网络。所有路线默认关闭，只有后台填写收款地址、RPC/API 并启用的路线才会出现在前台。BNB Smart Chain 常用 USDT/USDC 会明确标注为 Binance-Peg，不会冒充 Tether/Circle 原生资产。

= 页面金额包含手续费吗？ =

不包含。页面金额是网站必须完整收到的净到账金额。网络费、提币费和平台费均由付款方在页面金额之外承担。

= 监控需要手动刷新吗？ =

不需要。插件会注册每分钟 WP-Cron，收银台也会轮询状态。低访问量站点建议配置服务器每分钟 POST 到本站 `/wp-json/jiuliu-crypto/v1/cron`，并通过 `X-Jiuliu-Cron-Token` 请求头传递密钥。

= 监控会消耗链上手续费或 Energy 吗？ =

不会。监控只读取公开链上数据，不发送交易；它消耗的是 TronGrid/RPC 查询额度及服务器资源。

= 用户关闭子比订单后会怎样？ =

默认继续观察至后台设定的观察期。发现到账只转人工处理，不自动发货。管理员可以关闭该观察开关以减少查询，但可能无法自动发现付款后又关闭订单的情况。

= CoinGecko 汇率是官方结算价吗？ =

不是。CoinGecko 是第三方市场数据，只作报价参考。插件会检查数据新鲜度和相对备用固定汇率的偏差，异常时回退固定汇率。需要完全可控报价时请使用固定模式。

= 能自动退款吗？ =

不能。插件不持有私钥，退款必须由管理员使用外部钱包人工完成。

== Changelog ==

= 2.1.0 =

* 扩展为 36 条主网路线、5 种资产和 25 个网络，所有新增路线默认关闭。
* 新增 BNB Smart Chain 的 Binance-Peg USDT/USDC 与发行方原生 FDUSD，并显著区分托管锚定资产。
* 新增 Optimism、Celo、Linea、ZKsync、Unichain、World Chain、Ink、Sonic、Cronos、HyperEVM、Morph、Monad、Sei、XDC、Plume、Injective、Kava 和 Kaia 等路线。
* 新增 PYUSD 与 EURC，并将 CoinGecko 行情绑定到显式资产白名单，拒绝仅凭同名符号报价。
* 新增前台币种/网络两级选择器；无 JavaScript 时仍保留子比原生逐路线选择作为安全降级。
* 新增纯字符串大整数报价，正确处理 BSC 18 位代币和超过 PHP_INT_MAX 的链上金额。
* EVM 连接测试新增链上 decimals() 核验；链 ID、合约精度或 RPC 不匹配时拒绝启用测试。
* 继续醒目标明网站净到账金额，所有网络费及提币手续费均由付款方另行承担。

= 2.0.0 =

* 提供 USDT/USDC 七条主网支付路线及独立子比支付方式。
* 新增 TRON 与 EVM 链适配器，严格核验链、合约、地址、精确金额、时间、固化/确认状态和交易唯一性。
* 支持固定汇率和带新鲜度、偏差熔断、按路线回退的 CoinGecko 市场参考汇率。
* 支持关闭订单到账观察开关、异常付款人工处理和结算并发保护。
* 提供 WP-Cron、受保护的服务器 Cron、后台路线测试、日志与支付单管理。
* 在后台和收银台明确标注网站净到账金额及付款方手续费责任。
