# Changelog

## v0.3.0

- Added WHMCS `AfterCronJob` hook for built-in TronScan API polling.
- Added `tronscanApiKey`, `scanIntervalMinutes`, and `scanOverlapMinutes` gateway settings.
- Added `mod_owppgwu_payment_intents`, `mod_owppgwu_processed_transactions`, and `mod_owppgwu_scan_state` tables.
- Added latest-block confirmation calculation from TronScan block data.
- Changed default operating model from external watcher/webhook to WHMCS cron + TronScan API polling.
- Corrected TronScan transfer polling to use incoming `direction=2`, raw micro-USDT `amount`, top-level contract `id`, and paginated scans.
- Tightened intent matching so observed transfers must match the pending intent amount, receiving address snapshot, and USDT contract snapshot.
- Kept webhook callback as an optional signed fallback path.
- Updated tests to mock TronScan transfer responses and cron auto-credit behavior.

## v0.2.0

- Added WHMCS `tblcurrencies` based invoice currency to USD/USDT conversion.
- Added fail-closed handling for missing USD currency, invalid rates, invoice status changes, and invoice amount changes.
- Added payment request snapshots for invoice currency, invoice amount, WHMCS rates, computed USDT amount, displayed USDT amount, slot, expiry, txid, and status.
- Added transaction/request claim flow before `addInvoicePayment()` to avoid duplicate credits from repeated or concurrent callbacks.
- Added English and Chinese client-area payment instructions.
- Added repeatable Node scenario tests and PHP lint script.

## v0.1.0

- Initial WHMCS gateway module for USDT TRC20 single-address payments.
- Added 30-minute pending payment windows.
- Added `0.1 USDT` ceiling plus `+0.01` to `+0.09` calibration slots.
- Added HMAC-signed callback endpoint.
- Added automatic WHMCS invoice payment after exact amount matching.
- Added transaction records for unmatched, conflicting, wrong-address, wrong-contract, and low-confirmation callbacks.
