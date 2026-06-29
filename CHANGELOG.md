# Changelog

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
