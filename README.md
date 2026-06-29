# OWP-PGW-U · WHMCS USDT TRC20 Payment Gateway

> 面向 WHMCS 的 **USDT TRC20 单地址收款插件**。插件读取 WHMCS 货币表汇率，把发票原币种换算到 USD，再按 `USD 1:1 USDT` 生成 TRC20 精确付款金额。

![version](https://img.shields.io/badge/version-v0.2.0-blue)
![whmcs](https://img.shields.io/badge/WHMCS-gateway-113f67)
![network](https://img.shields.io/badge/network-TRC20-green)
![license](https://img.shields.io/badge/license-MIT-orange)

---

## 目录

- [功能特性](#功能特性)
- [货币转换](#货币转换)
- [金额规则](#金额规则)
- [工作流程](#工作流程)
- [快速开始](#快速开始)
- [WHMCS 配置项](#whmcs-配置项)
- [回调协议](#回调协议)
- [发票与并发安全](#发票与并发安全)
- [测试](#测试)
- [回滚](#回滚)
- [目录结构](#目录结构)
- [安全建议](#安全建议)
- [License](#license)

---

## 功能特性

| 类别 | 特性 |
|------|------|
| 收款网络 | USDT TRC20，默认校验 Tether USDt on TRON 合约地址 |
| 收款地址 | 单地址模式，适合只有一个 TRC20 收款地址的 WHMCS 站点 |
| 汇率来源 | 只读取 WHMCS `tblcurrencies`，不接第三方实时汇率 API |
| 币种处理 | 发票原币种 → USD；USD 按 `1:1` 作为 USDT |
| 金额识别 | 换算后的 USDT 向上取到 `0.1`，再用 `+0.01` 到 `+0.09` 做订单识别码 |
| 有效期 | 默认 30 分钟，过期后客户刷新账单重新分配付款金额 |
| 回调安全 | JSON 回调 + `X-OWP-Signature` HMAC-SHA256 签名 |
| 幂等处理 | `txid` 唯一记录，pending request 入账前原子 claim，重复回调不会重复入账 |
| 发票保护 | 只自动处理仍为 `Unpaid` 且余额未变化的发票 |
| 客户界面 | English / 中文双语支付说明 |
| 自动建表 | 模块在账单页或回调首次运行时自动创建或补齐 `mod_owppgwu_*` 数据表 |

---

## 货币转换

插件使用 WHMCS 后台 **System Settings → Currencies** 中配置的 Base Conv. Rate。WHMCS 的 rate 语义是：用某币种金额除以该币种 rate，得到基础货币金额。

插件公式：

```text
sourceBaseAmount = invoiceAmount / sourceCurrency.rate
usdAmount = sourceBaseAmount * usdCurrency.rate
usdtAmount = usdAmount
```

等价实现：

```text
usdtAmount = invoiceAmount / sourceCurrency.rate * usdCurrency.rate
```

示例：

```text
发票：HK$100.00
HKD rate：7.800000
USD rate：1.000000

100 / 7.8 * 1 = 12.820513 USDT
```

支付页会展示：

- WHMCS 发票金额和币种，例如 `HK$100.00`
- 汇率快照，例如 `HKD -> USD from WHMCS currency table`
- 换算后的 USD/USDT 金额
- 向上取到 `0.1 USDT` 后的基础金额
- 最终必须精确支付的 USDT TRC20 金额
- `USD is treated as 1:1 USDT`

Fail closed 规则：

- USD 货币不存在：不生成付款金额。
- USD rate 缺失或 `<= 0`：不生成付款金额。
- 发票币种不存在：不生成付款金额。
- 发票币种 rate 缺失或 `<= 0`：不生成付款金额。
- 检测到 WHMCS gateway 的 **Convert To For Processing** 改写了 `$params['currency']`：不生成付款金额。

请不要在该网关上启用 **Convert To For Processing**。本模块会自己读取原始发票币种和 WHMCS 货币表完成换算，启用 Convert To 会造成重复转换风险。

---

## 金额规则

插件给客户展示的 TRC20 应付金额分三步：

```text
1. 从 WHMCS 发票原币种换算到 USD/USDT
2. 向上取到 0.1 USDT
3. 在 30 分钟有效窗口内分配 +0.01 到 +0.09 的唯一校准尾数
```

示例：

| WHMCS 发票 | WHMCS rate | 换算 USDT | 0.1 基础金额 | 展示给客户 |
|------------|------------|-----------|--------------|------------|
| `HK$100.00` | `HKD=7.8, USD=1` | `12.820513` | `12.90` | `12.91` 到 `12.99` |
| `US$1.11` | `USD=1` | `1.110000` | `1.20` | `1.21` 到 `1.29` |
| `US$1.10` | `USD=1` | `1.100000` | `1.10` | `1.11` 到 `1.19` |

如果 30 分钟内已经有 9 张相同基础金额的未支付账单，新的客户会看到槽位已满提示，需要稍后刷新。

---

## 工作流程

```text
客户打开 WHMCS 账单
        │
        ▼
插件读取 tblinvoices / tblclients / tblcurrencies
        │
        ├─ 校验发票仍为 Unpaid
        ├─ 校验 USD 和发票币种 rate
        ├─ 发票原币种换算到 USD/USDT
        ├─ USDT 向上取到 0.1
        └─ 分配 +0.01 到 +0.09 尾数
        │
        ▼
客户精确转账到同一个 TRC20 地址
        │
        ▼
链上 watcher 检测 USDT 到账
        │
        ▼
watcher POST 回调 WHMCS
        │
        ▼
插件校验签名 / 地址 / 合约 / 金额 / 确认数
        │
        ▼
事务 claim txid + pending request
        │
        ▼
再次校验发票仍为 Unpaid 且余额未变化
        │
        ▼
addInvoicePayment 写入原发票币种金额
```

---

## 快速开始

1. 复制模块文件到 WHMCS 根目录：

```bash
cp -R modules /path/to/whmcs/
```

2. 登录 WHMCS 后台，进入 **System Settings → Payment Gateways**。

3. 激活 `OWP PGW U - USDT TRC20`。

4. 配置：

- `TRC20 USDT 收款地址`
- `回调 HMAC 密钥`
- `最低确认数`
- `账单有效分钟`
- `客服工单链接`

5. 在 **System Settings → Currencies** 中确认：

- 发票币种存在，例如 `HKD`。
- `USD` 货币存在。
- 发票币种和 USD 的 Base Conv. Rate 都大于 `0`。
- 如需每日更新汇率，在 WHMCS **Automation Settings** 中启用 Currency Auto Update，让 WHMCS cron 更新货币表。

6. 确认该 gateway 没有启用 **Convert To For Processing**。

7. 将链上 watcher 的回调地址设置为：

```text
https://你的WHMCS域名/modules/gateways/callback/owppgwu.php
```

---

## WHMCS 配置项

| 配置 | 默认值 | 说明 |
|------|--------|------|
| TRC20 USDT 收款地址 | 空 | 你的唯一 USDT TRC20 收款地址 |
| USDT TRC20 合约地址 | `TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t` | 用来过滤假币或其他 TRC20 代币 |
| 回调 HMAC 密钥 | 空 | 必填。watcher 用它对原始 JSON body 签名 |
| 最低确认数 | `12` | 回调确认数不足时不会入账 |
| 账单有效分钟 | `30` | 过期后释放金额尾数槽位 |
| 0.01 校准槽位 | `9` | 支持 `+0.01` 到 `+0.09` |
| 客服工单链接 | 空 | 未精确付款时展示给客户 |

---

## 回调协议

详见 [docs/callback-payload.md](docs/callback-payload.md)。

监听服务必须 POST JSON，并带上 HMAC 签名：

```http
POST /modules/gateways/callback/owppgwu.php
Content-Type: application/json
X-OWP-Signature: sha256=<hex_hmac_sha256(raw_body, webhook_secret)>
```

最小 payload：

```json
{
  "txid": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "from": "TSenderAddress...",
  "to": "TYourReceivingAddress...",
  "contract": "TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t",
  "amount": "12.91",
  "confirmations": 12
}
```

---

## 发票与并发安全

自动入账前必须全部满足：

- 链上 `to` 是配置的 TRC20 收款地址。
- 链上 `contract` 是配置的 USDT TRC20 合约。
- `confirmations` 达到配置值。
- `txid` 没有成功处理过。
- 到账金额精确匹配唯一一个有效 pending request。
- pending request 仍为 `pending`，且未被其他 callback claim。
- WHMCS 发票仍为 `Unpaid`。
- WHMCS 发票余额、币种与 pending request 快照一致。

`addInvoicePayment()` 写入的是 WHMCS 发票原币种余额金额，不是客户实际支付的 USDT 金额。USDT 实收金额保存在插件表中用于审计。

数据表记录：

| 表 | 关键字段 |
|----|----------|
| `mod_owppgwu_payment_requests` | `invoice_id`, `invoice_currency`, `invoice_amount`, `invoice_balance`, `source_currency_rate`, `usd_currency_rate`, `computed_usdt_micro`, `display_amount_micro`, `slot`, `expires_at`, `txid`, `status` |
| `mod_owppgwu_transactions` | `txid`, `request_id`, `invoice_id`, `from_address`, `to_address`, `contract_address`, `amount_micro`, `confirmations`, `status`, `payload` |

常见非自动入账状态：

| 状态 | 含义 |
|------|------|
| `waiting_confirmations` | 确认数不足，等待 watcher 再次回调 |
| `unmatched` | 没有找到相同金额的有效 pending request |
| `conflict` | 找到多张相同金额的有效 pending request |
| `wrong_address` | 回调收款地址不是配置地址 |
| `wrong_contract` | 回调合约地址不是 USDT TRC20 合约 |
| `invoice_not_payable` | 发票不是 `Unpaid` 或不能自动入账 |
| `invoice_amount_changed` | 发票余额或币种与 pending request 快照不一致 |
| `request_already_claimed` | pending request 已被其他 callback claim |
| `duplicate_whmcs_transaction` | WHMCS 已存在同 txid 交易 |
| `processed` | 已成功入账 |

人工处理建议见 [docs/manual-reconcile.md](docs/manual-reconcile.md)。

---

## 测试

运行场景测试：

```bash
npm test
```

PHP lint：

```bash
npm run lint:php
```

测试覆盖：

- HKD → USD → USDT 计算。
- USD 缺失 / rate 缺失时 fail closed。
- `0.1` ceiling + `+0.01` 到 `+0.09` 槽位。
- 过期 pending 释放槽位。
- wrong address / wrong contract。
- low confirmations。
- duplicate txid。
- invoice already paid / cancelled 不自动入账。
- invoice amount changed 不自动入账。
- 重复 callback / 同金额第二笔转账不重复 credit。

---

## 回滚

代码回滚：

1. 在 WHMCS 后台停用该支付网关。
2. 恢复上一版 `modules/gateways/owppgwu.php`、`modules/gateways/owppgwu/`、`modules/gateways/callback/owppgwu.php`。
3. 清理 opcode cache / PHP-FPM cache。

数据库回滚：

- 插件表只用于该网关审计，保留不会影响 WHMCS 核心表。
- 如必须删除，先备份数据库，再删除 `mod_owppgwu_payment_requests` 和 `mod_owppgwu_transactions`。

---

## 目录结构

```text
OWP-PGW-U/
├── modules/
│   └── gateways/
│       ├── owppgwu.php                 # WHMCS gateway 主模块
│       ├── owppgwu/
│       │   └── lib.php                 # 汇率 / 金额分配 / 建表 / claim helper
│       └── callback/
│           └── owppgwu.php             # watcher 回调入口
├── docs/
│   ├── callback-payload.md             # 回调 payload 和签名说明
│   └── manual-reconcile.md             # 未匹配付款人工处理建议
├── scripts/
│   └── php-lint.sh
├── tests/
│   └── owppgwu-scenarios.test.js
├── CHANGELOG.md
├── LICENSE
├── README.md
└── VERSION
```

---

## 安全建议

- 回调必须使用 HTTPS。
- `webhookSecret` 至少 32 字节随机字符串。
- watcher 必须只回调真实 USDT TRC20 `Transfer` 事件。
- watcher 回调前先确认 `to` 地址、`contract` 地址和确认数。
- 不要把 watcher API key、私钥或交易所账户密钥放进 WHMCS。
- 不要把生产收款地址或 secret 写进 README、PR 或日志。
- 这个插件只负责 WHMCS 展示、金额分配、回调校验和入账；链上扫描必须由独立 watcher 服务完成。

---

## License

MIT License. See [LICENSE](LICENSE).
