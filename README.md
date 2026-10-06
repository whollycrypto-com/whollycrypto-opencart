# Wholly Crypto for OpenCart

Add **Pay with crypto** to OpenCart using your own Wholly Crypto installation.
Customers pick a supported coin or token on your hosted checkout. Order updates
are verified server-side. No wallet private keys are stored in this extension.

[Download extension](https://github.com/whollycrypto-com/whollycrypto-opencart/releases/latest) · [Setup guide](https://www.whollycrypto.com/plugins/opencart/) · [Wholly Crypto](https://www.whollycrypto.com/)

## Requirements

- **OpenCart 4.1.x**, tested with native models on **4.1.0.4**. This release is not
  for OpenCart 3.x or 4.0.x. Test custom themes and checkout extensions on staging.
- PHP **8.1+**, cURL, JSON, valid CA certificates and an InnoDB shop database.
- A current Wholly Crypto merchant (7.8 API contract), with an enabled project/store
  and configured accepted payment methods, wallets and confirmations.
- Public HTTPS on your shop, API and checkout. Wholly must reach the callback
  without login, CAPTCHA or Basic Auth; the shop must reach Wholly's API.

## Install and connect

1. Back up the shop. Download **`whollycrypto.ocmod.zip`** from the release and verify
   the attached `SHA256SUMS`. **Keep this filename**: OpenCart derives the extension
   directory from it. Do not upload GitHub's automatic source ZIP.
2. Open **Extensions → Installer**, upload the ZIP, then install its package.
3. Open **Extensions → Extensions → Payments**, install **Wholly Crypto**, then Edit.
4. Enter the API origin (`https://api.example.com`) and checkout origin
   (`https://pay.example.com`), without paths. Use the same checkout domain that the
   selected Wholly store returns in its API links.
5. Copy the Wholly **Project/Store API UUIDs** from **Store → Basic → API IDs**.
   Create a read/write API credential restricted to that project.
6. Enable Wholly **Store → IPN** and paste its signing secret into the extension.
   The extension supplies the callback URL on each invoice; no extra webhook needed.
7. Review the four order statuses. The extension creates dedicated waiting, review
   and expired states. Only **Verified payment** may be an OpenCart processing or
   complete status. Choose four different states.
8. Save, **Test saved connection**, then enable **Accept new payments** after staging.

Blank secret fields preserve saved values; secrets are not rendered back into the
form. Customer email sharing is optional and disabled by default. The first release
uses the installation's shared payment configuration, not separate credentials per
OpenCart storefront. Use separate installations for independently connected shops.

## How orders are updated

The customer confirms an OpenCart order, then goes to Wholly checkout. The exact
fiat total uses the order's saved currency conversion and currency precision. A
saved idempotency key makes retries reuse the same invoice, including API timeouts.

The signed IPN is verified against the saved project, store, invoice, order, amount
and currency. The extension then fetches the authoritative invoice from the API.
Browser returns cannot fulfill orders. Native OpenCart order history and fraud
checks are used; duplicate events cannot repeat the paid transition or stock update.

| Invoice state | OpenCart handling |
| --- | --- |
| New / processing / partial / awaiting confirmations | Waiting |
| Settled, paid, no late/review flag | Configured paid status, subject to OpenCart fraud checks |
| Expired or cancelled without funds | Configured expired status |
| Overpaid, late, invalid, changed amount or funds on an already cancelled order | Review, never automatically revive the order |
| Payment changes after fulfillment | Review note, no automatic reversal or refund |

`payment.received` and `invoice.settled` can both contain `status: settled`. That is
not a second payment. Signed notification + current invoice + saved order identity
decide the action. [IPN & webhooks guide](https://www.whollycrypto.com/documentation/#delivery-history).

## If an order stays pending

1. Find its `opencart:<store-id>:<order-id>` reference in the Wholly invoice list.
2. Check **Store → IPN → History → Details**. Correct the secret, firewall, API or
   credit problem, then resend the signed delivery from Wholly.
3. Never mark an order paid from the return page alone. A payment can arrive while
   low Wholly processing credits pause notifications.

Callbacks return 401 for invalid signatures and 503 for temporary verification or
storage errors so delivery can be retried. Keep clocks synchronized. An uncertain
creation must retry the same order with its original API credential.

Changing connection details while invoices are open prevents their reconciliation.
Reconcile first. Disable new payments instead of uninstalling while payments remain
open. Native OpenCart uninstall may remove settings; the extension's payment table
and order history are retained. Keep protected shop/database backups.

## Scope and updates

Positive fiat orders, full-page hosted checkout, one connection per installation.
No automatic subscription debits, refunds, iframe mode or blockchain scanning in
the extension. Overpayments do not increase the shop order total. Stock reservation
and third-party fulfillment follow OpenCart's own status behavior.

For an update, back up, reconcile open payments and disable new payments. Verify the
new release ZIP, then replace the files inside `extension/whollycrypto/` with its
contents using your normal secure deployment/SFTP workflow; keep the directory name
and database records. Clear OpenCart's template cache, review settings, test and
re-enable. The native Installer refuses an already-existing directory, so do not
delete the payment extension or its settings just to force an update.
A Wholly merchant update does not update the extension.
Independent integration, not an OpenCart Marketplace endorsement or listing.
The extension adds no percentage fee; Wholly processing-credit rules still apply.

MIT licensed with an attributed, namespace-isolated public PHP SDK included.
No Composer installation required. See [testing](docs/testing.md), [security](SECURITY.md)
and [changelog](CHANGELOG.md).

```sh
php tests/protocol.php
python3 tests/https-flow.py
python3 tools/build.py
```
