# OWP-PGW-U · WHMCS USDT TRC20 Payment Gateway

> 面向 WHMCS 的 **USDT TRC20 单地址收款插件**。账单金额先向上取到 `0.1 USDT`，再用 `+0.01` 到 `+0.09` 的校准尾数区分同金额订单；客户精确付款后，由链上监听服务回调 WHMCS 自动入账。

![version](https://img.shields.io/badge/version-v0.1.0-blue)
![whmcs](https://img.shields.io/badge/WHMCS-gateway-113f67)
![network](https://img.shields.io/badge/network-TRC20-green)
![license](https://img.shields.io/badge/license-MIT-orange)

---

## 目录

- [功能特性](#功能特性)
- [金额规则](#金额规则)
- [工作流程](#工作流程)
- [快速开始](#快速开始)
- [WHMCS 配置项](#whmcs-配置项)
- [回调协议](#回调协议)
- [未精确付款处理](#未精确付款处理)
- [目录结构](#目录结构)
- [安全建议](#安全建议)
- [License](#license)

---

## 功能特性

| 类别 | 特性 |
|------|------|
| 收款网络 | USDT TRC20，默认校验 Tether USDt on TRON 合约地址 |
| 收款地址 | 单地址模式，适合只有一个 TRC20 收款地址的 WHMCS 站点 |
| 金额识别 | 基础金额向上取到 `0.1`，再用 `0.01` 尾数做订单识别码 |
| 有效期 | 默认 30 分钟，过期后客户刷新账单重新分配付款金额 |
| 回调安全 | JSON 回调 + `X-OWP-Signature` HMAC-SHA256 签名 |
| 幂等处理 | `txid` 查重，重复回调不会重复入账 |
| 异常兜底 | 金额不匹配、地址错误、合约错误、确认数不足都会记录交易状态 |
| 自动建表 | 模块在账单页或回调首次运行时自动创建 `mod_owppgwu_*` 数据表 |

---

## 金额规则

插件给客户展示的 TRC20 应付金额分两步：

```text
1. WHMCS 账单金额向上取到 0.1 USDT
2. 在 30 分钟有效窗口内分配 +0.01 到 +0.09 的唯一校准尾数
```

示例：

| WHMCS 账单 | 基础金额 | 展示给客户 |
|------------|----------|------------|
| `1.00` | `1.00` | `1.01` 到 `1.09` |
| `1.01` | `1.10` | `1.11` 到 `1.19` |
| `1.10` | `1.10` | `1.11` 到 `1.19` |
| `1.11` | `1.20` | `1.21` 到 `1.29` |

如果 30 分钟内已经有 9 张相同基础金额的未支付账单，新的客户会看到槽位已满提示，需要稍后刷新。

---

## 工作流程

```text
客户打开 WHMCS 账单
        │
        ▼
插件创建 pending 支付请求
        │
        ├─ 账单金额向上取到 0.1
        └─ 分配 +0.01 到 +0.09 尾数
        │
        ▼
客户精确转账到同一个 TRC20 地址
        │
        ▼
链上监听服务检测 USDT 到账
        │
        ▼
监听服务 POST 回调 WHMCS
        │
        ▼
插件校验签名 / 地址 / 合约 / 金额 / txid / 确认数
        │
        ▼
金额唯一匹配 pending 账单后自动 addInvoicePayment
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

5. 将链上监听服务的回调地址设置为：

```text
https://你的WHMCS域名/modules/gateways/callback/owppgwu.php
```

6. 在 WHMCS 货币配置中确认该网关的处理币种。若你的账单不是 USD，建议在 WHMCS 网关设置里使用 **Convert To For Processing** 转换到你希望按 USDT 计价的币种。

---

## WHMCS 配置项

| 配置 | 默认值 | 说明 |
|------|--------|------|
| TRC20 USDT 收款地址 | 空 | 你的唯一 USDT TRC20 收款地址 |
| USDT TRC20 合约地址 | `TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t` | 用来过滤假币或其他 TRC20 代币 |
| 回调 HMAC 密钥 | 空 | 必填。监听服务用它对原始 JSON body 签名 |
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
  "amount": "1.25",
  "confirmations": 12
}
```

---

## 未精确付款处理

账单页会明确提示客户：

```text
必须使用 TRC20 网络，并精确支付指定金额。
多付、少付或未按精确金额付款不会自动入账，请开工单联系客服处理。
```

回调无法唯一匹配时不会调用 `addInvoicePayment`，而是写入 `mod_owppgwu_transactions`：

| 状态 | 含义 |
|------|------|
| `waiting_confirmations` | 确认数不足，等待监听服务再次回调 |
| `unmatched` | 没有找到相同金额的有效 pending 账单 |
| `conflict` | 找到多张相同金额的有效 pending 账单 |
| `wrong_address` | 回调收款地址不是配置地址 |
| `wrong_contract` | 回调合约地址不是 USDT TRC20 合约 |
| `processed` | 已成功入账 |

人工处理建议见 [docs/manual-reconcile.md](docs/manual-reconcile.md)。

---

## 目录结构

```text
OWP-PGW-U/
├── modules/
│   └── gateways/
│       ├── owppgwu.php                 # WHMCS gateway 主模块
│       ├── owppgwu/
│       │   └── lib.php                 # 金额分配 / 建表 / 交易记录 helper
│       └── callback/
│           └── owppgwu.php             # 链上监听服务回调入口
├── docs/
│   ├── callback-payload.md             # 回调 payload 和签名说明
│   └── manual-reconcile.md             # 未匹配付款人工处理建议
├── CHANGELOG.md
├── LICENSE
├── README.md
└── VERSION
```

---

## 安全建议

- 回调必须使用 HTTPS。
- `webhookSecret` 至少 32 字节随机字符串。
- 链上监听服务必须只回调真实 USDT TRC20 `Transfer` 事件。
- 回调前先确认 `to` 地址、`contract` 地址和确认数。
- 不要把监听服务 API key、私钥或交易所账户密钥放进 WHMCS。
- 这个插件只负责 WHMCS 展示、金额分配、回调校验和入账；链上扫描建议由独立服务完成。

---

## License

MIT License. See [LICENSE](LICENSE).
