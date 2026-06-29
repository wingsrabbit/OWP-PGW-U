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
