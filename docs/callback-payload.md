# Callback Payload

OWP-PGW-U expects the blockchain watcher to call the WHMCS callback endpoint after it detects an incoming USDT TRC20 transfer to the configured receiving address.

```text
POST https://example.com/modules/gateways/callback/owppgwu.php
```

## Headers

| Header | Required | Description |
|--------|----------|-------------|
| `Content-Type: application/json` | Yes | Callback body must be JSON. |
| `X-OWP-Signature: sha256=<hex>` | Yes | HMAC-SHA256 of the raw request body using the WHMCS gateway `webhookSecret`. |

Signature pseudocode:

```text
signature = hex_hmac_sha256(raw_json_body, webhook_secret)
header = "sha256=" + signature
```

## Body

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

| Field | Required | Description |
|-------|----------|-------------|
| `txid` | Yes | TRON transaction id, 64 hex characters. |
| `from` | No | Sender address. Stored for reconciliation. |
| `to` | Yes | Receiver address. Must equal the gateway TRC20 address. |
| `contract` | Yes | TRC20 contract address. Must equal the configured USDT contract. |
| `amount` | Yes | Human USDT amount, up to 6 decimals. Must exactly match one pending WHMCS payment request. |
| `confirmations` | Yes | Must be greater than or equal to the configured minimum confirmations. |

The callback also accepts these aliases:

| Alias | Canonical field |
|-------|-----------------|
| `transaction_id` | `txid` |
| `from_address` | `from` |
| `to_address` | `to` |
| `contract_address` | `contract` |

## Responses

| HTTP | Body status | Meaning |
|------|-------------|---------|
| `200` | `paid` | The invoice was matched and paid. |
| `200` | `duplicate_processed` | The transaction had already been processed. |
| `202` | `waiting_confirmations` | The transfer is valid but confirmations are not enough yet. |
| `202` | `unmatched` | No active invoice has this exact amount. |
| `202` | `conflict` | More than one active invoice has this exact amount. |
| `202` | `wrong_address` | The receiver address does not match the configured address. |
| `202` | `wrong_contract` | The contract is not the configured USDT TRC20 contract. |
| `400` | `invalid_json`, `invalid_txid`, `invalid_amount` | The payload is malformed. |
| `403` | `signature_failed` | The HMAC signature is missing or invalid. |
| `503` | `gateway_not_active`, `webhook_secret_missing` | WHMCS module is not ready. |

## Example signer

```bash
body='{"txid":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef","from":"TSenderAddress","to":"TYourReceivingAddress","contract":"TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t","amount":"1.25","confirmations":12}'
secret='change-me'
sig=$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$secret" -binary | xxd -p -c 256)

curl -X POST 'https://example.com/modules/gateways/callback/owppgwu.php' \
  -H 'Content-Type: application/json' \
  -H "X-OWP-Signature: sha256=$sig" \
  --data "$body"
```
