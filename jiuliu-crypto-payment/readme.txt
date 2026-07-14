=== 九流网络多链加密货币支付 ===
Contributors: jiuliu
Tags: usdt, usdc, trc20, erc20, zibll
Requires at least: 6.0
Tested up to: 7.0.1
Requires PHP: 7.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

为子比（Zibll）主题提供 USDT/USDC 多链收银台、严格链上核验和原生订单交付。

== Description ==

插件针对子比 V9 支付接口开发，不修改主题文件。

主要功能：

* USDT：TRON、Ethereum
* USDC：Ethereum、Base、Arbitrum One、Polygon PoS、Avalanche C-Chain
* 每条币种/网络路线作为独立的子比支付方式
* TronGrid 与 EVM JSON-RPC 只读链上核验
* 精确六位代币金额及原始整数金额匹配
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

提供 USDT 的 TRON、Ethereum 路线，以及 USDC 的 Ethereum、Base、Arbitrum One、Polygon PoS、Avalanche C-Chain 路线。所有路线默认关闭，管理员可以分别启用。

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

= 2.0.0 =

* 提供 USDT/USDC 七条主网支付路线及独立子比支付方式。
* 新增 TRON 与 EVM 链适配器，严格核验链、合约、地址、精确金额、时间、固化/确认状态和交易唯一性。
* 支持固定汇率和带新鲜度、偏差熔断、按路线回退的 CoinGecko 市场参考汇率。
* 支持关闭订单到账观察开关、异常付款人工处理和结算并发保护。
* 提供 WP-Cron、受保护的服务器 Cron、后台路线测试、日志与支付单管理。
* 在后台和收银台明确标注网站净到账金额及付款方手续费责任。
