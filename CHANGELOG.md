# Changelog

## v0.1.0

- Initial WHMCS gateway module for USDT TRC20 single-address payments.
- Added 30-minute pending payment windows.
- Added `0.1 USDT` ceiling plus `+0.01` to `+0.09` calibration slots.
- Added HMAC-signed callback endpoint.
- Added automatic WHMCS invoice payment after exact amount matching.
- Added transaction records for unmatched, conflicting, wrong-address, wrong-contract, and low-confirmation callbacks.
