# Manual Reconciliation

Most payments should be processed automatically by the WHMCS cron hook that polls TronScan API. Manual handling is only needed when the customer did not pay the exact amount, paid after expiry, used the wrong network, or when the scan cannot match one active intent.

## Where to look

OWP-PGW-U writes observed transactions into:

```text
mod_owppgwu_processed_transactions
```

Important columns:

| Column | Description |
|--------|-------------|
| `txid` | Chain transaction id. |
| `from_address` | Sender address from TronScan or the optional callback. |
| `to_address` | Receiver address from TronScan or the optional callback. |
| `contract_address` | TRC20 contract address from TronScan or the optional callback. |
| `raw_amount` | Raw token amount from TronScan. For USDT TRC20 this is micro-USDT. |
| `amount_usdt` | Human USDT amount. |
| `amount_micro` | USDT amount in 6-decimal micro units. |
| `block_number` | Transfer block number. |
| `block_timestamp` | Transfer block timestamp. |
| `confirmations` | Confirmation count calculated from TronScan blocks, or supplied by the optional callback. |
| `status` | Processing result. |
| `raw_json` | Original TronScan row or optional callback JSON. |
| `processed_at` | Time the module stored or updated the transaction row. |

Pending invoice payment requests live in:

```text
mod_owppgwu_payment_intents
```

Important request columns:

| Column | Description |
|--------|-------------|
| `invoice_id` | WHMCS invoice id. |
| `invoice_currency` | Original invoice currency code. |
| `invoice_amount_snapshot` | Original invoice currency amount snapshot. |
| `invoice_balance_snapshot` | Original invoice balance snapshot to credit on success. |
| `source_currency_rate` | WHMCS currency table rate used for the source currency. |
| `usd_currency_rate` | WHMCS currency table rate used for USD. |
| `computed_usdt_micro_amount` | Converted USD/USDT amount before `0.1` ceiling. |
| `expected_usdt_amount` | Exact human USDT amount shown to the customer. |
| `expected_usdt_micro_amount` | Exact micro-USDT amount required for automatic matching. |
| `trc20_address` | Receiving address snapshot. |
| `usdt_contract` | USDT contract snapshot. |
| `slot` | `0.01` calibration slot. |
| `expires_at` | Pending request expiry. |
| `txid` | Claimed or paid transaction id. |
| `status` | Request processing state. |

## Suggested handling

1. Verify the transaction on a TRON explorer.
2. Confirm the transfer is USDT TRC20 and the receiver address is yours.
3. Confirm the transaction has enough confirmations.
4. Ask the customer for the WHMCS invoice id and txid in a support ticket.
5. If the payment should be accepted, add the invoice payment manually in WHMCS admin.
6. Update your internal note with the txid so the same transfer is not credited twice.

## Common statuses

| Status | Action |
|--------|--------|
| `unmatched` | Usually wrong amount, expired invoice, or no pending invoice. Ask for invoice id. |
| `conflict` | Very unlikely with active amount keys. Inspect the duplicate requests before crediting. |
| `waiting_confirmations` | Wait for a later cron scan after enough confirmations. |
| `confirmations_unavailable` | Latest block or transfer block could not be read; do not credit automatically. |
| `wrong_address` | Do not credit unless you control the receiver address and verified it separately. |
| `wrong_contract` | Do not credit as USDT unless the contract is verified separately. |
| `invalid_transfer_event` | Transfer row is not a TRC20 `Transfer` event. |
| `failed_contract_result` | Transfer did not have `contract_ret=SUCCESS`. |
| `reverted_transfer` | Transfer had `revert` other than `0`. |
| `invoice_not_payable` | The invoice is not `Unpaid`. Verify before any manual credit. |
| `invoice_amount_changed` | Invoice balance or currency changed after the payment request was created. Recalculate manually. |
| `request_already_claimed` | Another scan or callback claimed the pending intent. Check tx history before crediting. |
| `duplicate_whmcs_transaction` | WHMCS already has the txid in transaction records. Do not credit twice. |
| `payment_failed` | The module claimed the request but `addInvoicePayment()` failed. Inspect WHMCS logs. |

When manually crediting, add the WHMCS invoice amount in the original invoice currency. Do not add the raw USDT amount as the WHMCS payment amount unless the invoice currency is USD and the amount is intentionally the same.
