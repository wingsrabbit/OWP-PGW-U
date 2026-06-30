# OWP-PGW-U · WHMCS USDT TRC20 Payment Gateway

> 面向 WHMCS 的 **USDT TRC20 单地址收款插件**。默认工作模式是 **WHMCS cron hook 轮询 TronScan API**，插件自己完成到账监听、匹配和入账，不需要外部 watcher/webhook 服务。

![version](https://img.shields.io/badge/version-v0.3.0-blue)
![whmcs](https://img.shields.io/badge/WHMCS-gateway-113f67)
![network](https://img.shields.io/badge/network-TRC20-green)
![license](https://img.shields.io/badge/license-MIT-orange)

---

## 目录

- [功能特性](#功能特性)
- [货币转换](#货币转换)
- [金额规则](#金额规则)
- [Cron 监听](#cron-监听)
- [快速开始](#快速开始)
- [WHMCS 配置项](#whmcs-配置项)
- [TronScan API](#tronscan-api)
- [可选 Webhook](#可选-webhook)
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
| 默认监听 | WHMCS `AfterCronJob` hook 定时轮询 TronScan API |
| 收款网络 | USDT TRC20，默认校验 Tether USDt on TRON 合约地址 |
| 收款地址 | 单地址模式，适合只有一个 TRC20 收款地址的 WHMCS 站点 |
| 汇率来源 | 只读取 WHMCS `tblcurrencies`，不接第三方实时汇率 API |
| 币种处理 | 发票原币种 → USD；USD 按 `1:1` 作为 USDT |
| 金额识别 | 换算后的 USDT 向上取到 `0.1`，再用 `+0.01` 到 `+0.09` 做订单识别码 |
| 有效期 | 默认 30 分钟，过期后客户刷新账单重新分配付款金额 |
| 确认数 | 用 TronScan 最新区块高度减 transfer `block` 计算确认数 |
| 幂等处理 | `txid` 唯一记录，intent 入账前原子 claim，重复扫描不会重复入账 |
| 发票保护 | 只自动处理仍为 `Unpaid` 且余额未变化的发票 |
| 客户界面 | English / 中文双语支付说明 |
| 自动建表 | 模块在账单页或 cron 首次运行时自动创建或补齐 `mod_owppgwu_*` 数据表 |

---

## 货币转换

插件使用 WHMCS 后台 **System Settings → Currencies** 中配置的 Base Conv. Rate。WHMCS 的 rate 语义是：用某币种金额除以该币种 rate，得到基础货币金额。

插件公式：

```text
sourceBaseAmount = invoiceAmount / sourceCurrency.rate
usdAmount = sourceBaseAmount * usdCurrency.rate
usdtAmount = usdAmount
```

示例：

```text
发票：HK$100.00
HKD rate：7.800000
USD rate：1.000000

100 / 7.8 * 1 = 12.820513 USDT
```

Fail closed 规则：

- USD 货币不存在：不生成付款金额。
- USD rate 缺失或 `<= 0`：不生成付款金额。
- 发票币种不存在：不生成付款金额。
- 发票币种 rate 缺失或 `<= 0`：不生成付款金额。
- TRC20 收款地址为空：不生成付款金额。
- TronScan API Key 为空：不生成付款金额。
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

金额固定写入 payment intent。客户刷新账单页时，如果 intent 仍未过期且发票余额未变，继续复用同一个金额，不会每次刷新变化。

示例：

| WHMCS 发票 | WHMCS rate | 换算 USDT | 0.1 基础金额 | 展示给客户 |
|------------|------------|-----------|--------------|------------|
| `HK$100.00` | `HKD=7.8, USD=1` | `12.820513` | `12.90` | `12.91` 到 `12.99` |
| `US$1.11` | `USD=1` | `1.110000` | `1.20` | `1.21` 到 `1.29` |
| `US$1.10` | `USD=1` | `1.100000` | `1.10` | `1.11` 到 `1.19` |

所有 USDT 比较都使用整数 micro-USDT：

```text
12.91 USDT = 12910000 micro-USDT
```

---

## Cron 监听

```text
客户打开 WHMCS 账单
        │
        ▼
插件创建 / 复用 pending payment intent
        │
        ▼
客户精确转账到同一个 TRC20 地址
        │
        ▼
WHMCS cron 触发 AfterCronJob hook
        │
        ▼
插件查询 TronScan /api/block 获取最新区块
        │
        ▼
插件查询 TronScan /api/transfer/trc20 获取收款地址 USDT 转账
        │
        ▼
校验 to / contract / Transfer / SUCCESS / revert=0 / 确认数 / raw amount
        │
        ▼
txid + intent 原子 claim
        │
        ▼
再次校验发票仍为 Unpaid 且余额未变化
        │
        ▼
addInvoicePayment 写入原发票币种金额
```

确认数计算：

```text
confirmations = latestBlockNumber - transfer.block
```

如果无法获取最新区块，或 transfer 没有 block，插件 fail closed，不会自动入账。

---

## 快速开始

1. 复制模块文件到 WHMCS 根目录：

```bash
cp -R modules includes /path/to/whmcs/
```

2. 登录 WHMCS 后台，进入 **System Settings → Payment Gateways**。

3. 激活 `OWP PGW U - USDT TRC20`。

4. 配置：

- `TRC20 USDT 收款地址`
- `USDT TRC20 合约地址`
- `TronScan API Key`
- `最低确认数`
- `账单有效分钟`
- `TronScan 扫描间隔分钟`
- `TronScan 扫描重叠分钟`
- `客服工单链接`

5. 在 **System Settings → Currencies** 中确认：

- 发票币种存在，例如 `HKD`。
- `USD` 货币存在。
- 发票币种和 USD 的 Base Conv. Rate 都大于 `0`。
- 如需每日更新汇率，在 WHMCS **Automation Settings** 中启用 Currency Auto Update，让 WHMCS cron 更新货币表。

6. 确认该 gateway 没有启用 **Convert To For Processing**。

7. 确认 WHMCS cron 正常运行。插件通过 `includes/hooks/owppgwu_cron.php` 挂载 `AfterCronJob`，cron 运行时会按配置间隔扫描 TronScan。

---

## WHMCS 配置项

| 配置 | 默认值 | 说明 |
|------|--------|------|
| TRC20 USDT 收款地址 | 空 | 你的唯一 USDT TRC20 收款地址。为空时前台支付 fail closed |
| USDT TRC20 合约地址 | `TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t` | 用来过滤假币或其他 TRC20 代币 |
| TronScan API Key | 空 | 必填。cron 请求 TronScan API 时作为 `TRON-PRO-API-KEY` header |
| 回调 HMAC 密钥 | 空 | 可选 webhook 入口使用。默认 cron 模式不需要 |
| 最低确认数 | `12` | TronScan 最新区块高度减 transfer block 必须大于等于此值 |
| 账单有效分钟 | `30` | 过期后释放金额尾数槽位 |
| TronScan 扫描间隔分钟 | `5` | WHMCS cron 至少间隔这些分钟才执行一次扫描 |
| TronScan 扫描重叠分钟 | `30` | 每次从上次扫描时间往前重叠这些分钟，避免漏扫 |
| 0.01 校准槽位 | `9` | 支持 `+0.01` 到 `+0.09` |
| 客服工单链接 | 空 | 未精确付款时展示给客户 |

---

## TronScan API

Base URL:

```text
https://apilist.tronscanapi.com/api
```

最新区块：

```text
GET /block?sort=-number&limit=1&start=0
```

TRC20 转账扫描：

```text
GET /transfer/trc20
```

查询参数：

| 参数 | 值 |
|------|----|
| `address` | 网关配置里的 TRC20 收款地址 |
| `trc20Id` | USDT TRC20 合约地址 |
| `direction` | `1` |
| `reverse` | `true` |
| `db_version` | `1` |
| `start` | `0` |
| `limit` | `50` |
| `start_timestamp` | 上次扫描时间减 overlap |
| `end_timestamp` | 当前时间 |

请求 header：

```text
TRON-PRO-API-KEY: <TronScan API Key>
```

---

## 可选 Webhook

`modules/gateways/callback/owppgwu.php` 仍保留为可选手动入口，用于测试或紧急补偿。生产默认路径不依赖它；不配置 `webhookSecret` 也不会影响 cron 扫描。

详见 [docs/callback-payload.md](docs/callback-payload.md)。

---

## 发票与并发安全

自动入账前必须全部满足：

- `to` 等于配置的 TRC20 收款地址。
- `trc20Id` / token id 等于配置的 USDT TRC20 合约。
- `event_type` 等于 `Transfer`。
- `contract_ret` 等于 `SUCCESS`。
- `revert` 等于 `0`。
- `raw amount` 等于 intent 的 `expected_usdt_micro_amount`。
- `txid` 没有处理过。
- intent 仍为 `pending` 且未过期。
- WHMCS 发票仍为 `Unpaid`。
- WHMCS 发票余额、币种与 intent 创建时快照一致。

`addInvoicePayment()` 写入的是 WHMCS 发票原币种余额金额，不是客户实际支付的 USDT 金额。USDT 实收金额保存在插件表中用于审计。

数据表：

| 表 | 用途 |
|----|------|
| `mod_owppgwu_payment_intents` | 发票金额、币种、余额快照、WHMCS 汇率、expected USDT、micro-USDT、地址、合约、状态、过期时间 |
| `mod_owppgwu_processed_transactions` | 唯一 txid、intent/invoice、from/to、raw amount、USDT amount、block、timestamp、raw JSON、processed_at |
| `mod_owppgwu_scan_state` | TronScan 上次扫描时间、扫描锁、错误信息 |

常见非自动入账状态：

| 状态 | 含义 |
|------|------|
| `waiting_confirmations` | 确认数不足，等待下次 cron 扫描 |
| `confirmations_unavailable` | 无法计算确认数，fail closed |
| `unmatched` | 没有找到相同 micro-USDT 金额的有效 intent |
| `conflict` | 找到多张相同金额的有效 intent |
| `wrong_address` | 收款地址不是配置地址 |
| `wrong_contract` | 合约地址不是 USDT TRC20 合约 |
| `invalid_transfer_event` | 不是 `Transfer` 事件 |
| `failed_contract_result` | `contract_ret` 不是 `SUCCESS` |
| `reverted_transfer` | `revert` 不是 `0` |
| `invoice_not_payable` | 发票不是 `Unpaid` 或不能自动入账 |
| `invoice_amount_changed` | 发票余额或币种与 intent 快照不一致 |
| `request_already_claimed` | intent 已被其他扫描或回调 claim |
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
- 过期 intent 释放槽位。
- micro-USDT raw amount 精确匹配。
- mock TronScan API 响应触发 cron 自动入账。
- wrong address / wrong contract。
- low confirmations。
- duplicate txid。
- invoice already paid / cancelled 不自动入账。
- invoice amount changed 不自动入账。
- 重复扫描 / 同金额第二笔转账不重复 credit。

---

## 回滚

代码回滚：

1. 在 WHMCS 后台停用该支付网关。
2. 恢复上一版 `modules/gateways/owppgwu.php`、`modules/gateways/owppgwu/`、`modules/gateways/callback/owppgwu.php`、`includes/hooks/owppgwu_cron.php`。
3. 清理 opcode cache / PHP-FPM cache。

数据库回滚：

- 插件表只用于该网关审计，保留不会影响 WHMCS 核心表。
- 如必须删除，先备份数据库，再删除 `mod_owppgwu_payment_intents`、`mod_owppgwu_processed_transactions`、`mod_owppgwu_scan_state`。

---

## 目录结构

```text
OWP-PGW-U/
├── includes/
│   └── hooks/
│       └── owppgwu_cron.php            # WHMCS AfterCronJob TronScan 轮询
├── modules/
│   └── gateways/
│       ├── owppgwu.php                 # WHMCS gateway 主模块
│       ├── owppgwu/
│       │   └── lib.php                 # 汇率 / intent / TronScan / claim helper
│       └── callback/
│           └── owppgwu.php             # 可选 webhook 入口
├── docs/
│   ├── callback-payload.md             # 可选 webhook payload 和签名说明
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

- WHMCS cron 必须稳定运行，否则不会自动确认付款。
- TronScan API Key 使用网关后台 password 字段保存，不要写进 README、PR 或日志。
- `webhookSecret` 只用于可选 webhook；默认 cron 模式不需要外部 watcher。
- 插件只接受真实 USDT TRC20 `Transfer`、`SUCCESS`、`revert=0` 且确认数足够的转账。
- 不要把生产收款地址、secret、API key 写进 README、PR 或日志。

---

## License

MIT License. See [LICENSE](LICENSE).
