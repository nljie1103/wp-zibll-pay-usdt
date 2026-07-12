=== 九流网络 USDT 支付 ===
Contributors: jiuliu
Tags: usdt, trc20, tron, zibll, payment
Requires at least: 6.0
Tested up to: 7.0.1
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

为子比（Zibll）9.0 提供 USDT-TRC20 原生收银台支付、链上自动核验和原生订单交付。

== Description ==

本插件针对 Zibll 9.0 的真实支付接口开发，不修改主题文件。

主要功能：

* USDT-TRC20 出现在子比原生收银台
* 六位小数精确金额匹配
* TronGrid 已确认交易核验
* 交易哈希唯一约束，防止重复结算
* 原生支持余额充值、会员、付费文章、资源、图片、视频、商城和论坛付费关注
* 子比多商品/多作者关联订单统一结算
* 前台二维码、复制地址、复制金额、倒计时和交易哈希核验
* 后台支付单、异常订单、补单、日志和连接测试
* WP-Cron 兜底及带密钥/IP 白名单的系统 Cron 接口
* 固定汇率或 CoinGecko 自动汇率，自动模式失败时回退固定汇率
* 不保存钱包私钥或助记词

== Installation ==

1. 在 WordPress 后台上传并启用插件。
2. 打开“USDT 收款 -> 设置”。
3. 填写公开的 TRON（TRC20）收款地址。
4. 配置固定汇率；生产环境建议再填写 TronGrid API Key。
5. 保存后到“系统状态”测试 TronGrid 和汇率。
6. 配置服务器每分钟 Cron，并保留 WP-Cron 作为兜底。
7. 先用小额真实订单完成全流程验收，再正式启用。

警告：不要向插件填写或上传私钥、助记词、钱包密码或 WordPress 管理员密码。

== Frequently Asked Questions ==

= 支持哪些链？ =

1.0.0 只支持 TRON 主网 USDT-TRC20。其他链转入不会被识别。

= 支持哪些子比订单？ =

通过 Zibll 9.0 统一付款接口支持余额充值、会员、付费内容和资源、商城关联订单及论坛付费关注。权益由子比原生 payment_order_success 监听器发放，插件不会直接修改余额字段。

= 为什么金额有六位小数？ =

TRC20 普通转账没有订单备注。插件使用收款地址、精确金额、USDT 合约、确认状态和付款时间匹配支付单。

= 过期、少付或多付会怎样？ =

插件会阻止自动发货并转入人工处理。过期商城订单可能已经恢复库存，因此不会盲目重新打开。

= 能自动退款吗？ =

不能，也不应该。插件不持有私钥，因此 USDT 退款必须由管理员在钱包中人工完成。

== Changelog ==

= 1.0.0 =

* 首个针对 Zibll 9.0 的完整版本。

