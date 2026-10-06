# Public OpenCart integration

Only this extension, its tests and bundled public MIT PHP SDK belong here. No server
implementation, credentials, customer exports or private deployment helpers.
Read README.md and docs/testing.md. Check Git status before editing.
Keep amount/identity checks, saved idempotency keys, signed callbacks and native
order-history deduplication intact. Browser returns must never fulfill orders.
Build installable ZIPs with tools/build.py. Keep the ZIP basename whollycrypto.ocmod.zip;
OpenCart derives its extension directory from that name. Test with synthetic fixtures.
Publication is a separate, explicitly authorized step.
