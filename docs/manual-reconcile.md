# Manual Reconciliation

Most payments should be processed automatically. Manual handling is only needed when the customer did not pay the exact amount, paid after expiry, used the wrong network, or when the watcher sends a callback that cannot match one active invoice.

## Where to look

OWP-PGW-U writes every accepted callback into:

```text
mod_owppgwu_transactions
```

Important columns:

| Column | Description |
|--------|-------------|
| `txid` | Chain transaction id. |
| `from_address` | Sender address if provided by the watcher. |
| `to_address` | Receiver address from the callback. |
| `contract_address` | TRC20 contract address from the callback. |
| `amount_micro` | USDT amount in 6-decimal micro units. |
| `confirmations` | Confirmation count from the callback. |
| `status` | Processing result. |
| `payload` | Original callback JSON. |

Pending invoice payment requests live in:

```text
mod_owppgwu_payment_requests
```

Important request columns:

| Column | Description |
|--------|-------------|
| `invoice_id` | WHMCS invoice id. |
| `invoice_currency` | Original invoice currency code. |
| `invoice_amount` | Original invoice currency amount to credit on success. |
| `source_currency_rate` | WHMCS currency table rate used for the source currency. |
| `usd_currency_rate` | WHMCS currency table rate used for USD. |
| `computed_usdt_micro` | Converted USD/USDT amount before `0.1` ceiling. |
| `display_amount_micro` | Exact USDT amount shown to the customer. |
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
| `waiting_confirmations` | Let the watcher call back again after enough confirmations. |
| `wrong_address` | Do not credit unless you control the receiver address and verified it separately. |
| `wrong_contract` | Do not credit as USDT unless the contract is verified separately. |
| `invoice_not_payable` | The invoice is not `Unpaid`. Verify before any manual credit. |
| `invoice_amount_changed` | Invoice balance or currency changed after the payment request was created. Recalculate manually. |
| `request_already_claimed` | Another callback claimed the pending request. Check tx history before crediting. |
| `duplicate_whmcs_transaction` | WHMCS already has the txid in transaction records. Do not credit twice. |
| `payment_failed` | The module claimed the request but `addInvoicePayment()` failed. Inspect WHMCS logs. |

When manually crediting, add the WHMCS invoice amount in the original invoice currency. Do not add the raw USDT amount as the WHMCS payment amount unless the invoice currency is USD and the amount is intentionally the same.
