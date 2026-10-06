# Verification

`python3 tests/package.py` checks SDK provenance, byte-for-byte reproducibility,
the release allowlist and an extracted package's autoloader. The installer and
payment path also refuse non-InnoDB order storage or unsafe fulfillment statuses.

Run `php tests/protocol.php`, `python3 tests/https-flow.py` and `python3 tools/build.py`.
TLS fixtures bind localhost, use an isolated certificate and synthetic invoices.
No real merchant, blockchain or funds are accessed.

`tests/repository.php` needs PDO MySQL and `WHOLLY_TEST_DB_DSN` (optionally user/password
variables). It creates/removes a random table; use a disposable database only.

Native tests require an isolated OpenCart 4.1.0.4 installation at `shop.example.test`,
with the extension at `extension/whollycrypto`. Set `WHOLLY_TEST_DISPOSABLE=yes`,
`WHOLLY_TEST_OPENCART_ROOT` and `WHOLLY_TEST_FLOW=platform.php`, then run the TLS script.
`PHP_BINARY` can select PHP with the fixture's extensions/database socket. Synthetic
orders use real OpenCart models and MySQL. This is **not for production databases**.

Native coverage includes install, method discovery, order status/history, real TLS
creation and reads, duplicate/stale callbacks, lost-response recovery, partial and
late payments, edited totals and reorg notes. It does not certify third-party themes,
one-page checkouts or real blockchain payment behavior.

## Shop staging checklist

1. Back up, install and configure a separate Wholly test store.
2. Test guest and registered checkout, tax, coupons, shipping and currency conversion.
3. Verify final fiat totals and payment-method availability, including mobile.
4. Complete a small controlled payment and check only one paid history/stock update.
5. Resend duplicate and old callbacks; check partial, expiry, late and changed orders.
6. Verify API outage recovery reuses the original invoice and key.
7. Confirm forged IPN and browser returns never fulfill an order.
8. Test custom fulfillment, mail and fraud extensions before enabling production.
