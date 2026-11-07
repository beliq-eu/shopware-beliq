# shopware-beliq

A Shopware 6 plugin that turns store orders into compliant EN 16931 e-invoices
(XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the [beliq](https://beliq.eu)
API. beliq produces and checks the compliant document; transmission, archiving,
and tax-authority reporting stay with your access point.

## Status

Version 0.1.0 is on Packagist as
[`beliq/shopware-beliq`](https://packagist.org/packages/beliq/shopware-beliq),
built from the tag `v0.1.0`. It is not in the Shopware Store.

Built and unit tested:

- The framework-agnostic core: the order-to-EN 16931 mapper (VAT category
  derivation, tax breakdown, EN 16931 rounding and totals) and the beliq API
  client.
- The Shopware runtime wiring. An order-state subscriber listens for payment
  paid and order completed. On the one the merchant picked, it asks Shopware's
  `DocumentGenerator` for a `beliq_invoice` document. That document type's
  renderer reloads the order, applies the business-only gate, maps the order,
  calls the beliq API, and hands back the bytes, which Shopware stores as an
  order document.

Every pull request and push to `main` runs the PHPUnit suite on PHP 8.2, 8.3
and 8.4, plus the Shopware Store check
(`shopware-cli extension validate --store-compliance`). The Store check also
runs weekly. It includes PHPStan, which `phpstan.neon` sets to level 8 over the
plugin code outside `tests/`, on top of shopware-cli's own Shopware deprecation
and disallowed-call rules.

Verified end to end on a local Shopware 6.7 (Dockware) instance talking to a
local beliq API and engine. A business order's
paid transition fired the subscriber and produced a `beliq_invoice` document
with its file on disk, for both XML and hybrid-PDF output. Generating again
from the admin produced a second document with its own number. Separately,
XRechnung and German Peppol BIS invoices built by the plugin's mapper and client
validated with zero errors against the same local API and engine
(`LiveGenerateSmokeTest`, which runs only when `BELIQ_API_KEY` is set). On
2026-11-05 the same test passed 3 of 3 against the production API, a Peppol BIS
invoice from a non-German order included.

Still open:

- The Dockware run has not been repeated against the production API
  (`api.beliq.eu`).
- Non-standard VAT categories (reverse charge, intra-community, export) carry no
  exemption reason. The beliq API accepts one; the plugin does not send it.

## What it does

- Reads a completed order and builds a valid EN 16931 invoice from its lines,
  shipping, taxes, parties, and totals. Shipping becomes its own line, split per
  VAT rate when the shipping method spreads its tax across the cart's rates.
- Sends it to beliq to generate the document in the format you choose, and can
  validate it against the authority-pinned rules.
- Stores the resulting document on the order.

The plugin generates and validates; it does not transmit. Peppol delivery,
e-mail, and filing remain with the merchant.

## Scope of generation

By default the plugin generates a structured invoice for business orders (the
buyer carries a VAT ID, or the order is flagged business at checkout). This
matches where a structured e-invoice is legally meaningful. A merchant can widen
generation to all orders.

Lines taxed at a standard rate are mapped to VAT category `S`. A zero-rated line
takes a merchant-configured category (default `Z`). Cross-border reverse charge
and intra-community supply are the merchant's call to configure. They are not
auto-detected: a wrong guess in a compliance tool is worse than none.

## Install

In the root of your Shopware 6.7 project:

```bash
composer require beliq/shopware-beliq
bin/console plugin:refresh
bin/console plugin:install --activate BeliqShopware
bin/console cache:clear
```

`BeliqShopware` is the plugin's name in Shopware, taken from its plugin class.
Then configure it as described under Setup.

## Setup

In the plugin settings (Extensions -> beliq e-invoicing -> Configure):

- Enter your beliq API key.
- Fill in the seller legal details (name, VAT ID, address). EN 16931 requires
  them, so the seller block is the merchant's to complete.
- Pick the document format (ZUGFeRD / Factur-X hybrid PDF by default), profile,
  and whether to output a hybrid PDF or XML only.
- Choose when to generate: on payment paid (default) or on order completed.
- Turn on `Generate invoices automatically`. Generation is off until you do.

The generated document is stored as a beliq e-invoice document on the order (its
Documents tab), with its file kept as private media. It is downloadable there as
XML or a hybrid PDF, depending on the format you chose.

## When beliq is unavailable

The order's state change always goes through. When the automatic invoice's
first attempt fails, the plugin records the failure on the order (Orders, the
order's Details tab, Custom fields, "beliq e-invoice": when it failed, the beliq
error code or the connection failure, and when the next attempt is due) and,
for a failure a wait can help, tries again later. What is retried, and when
(`src/Retry/RetrySchedule.php`), follows the beliq SDKs (`@beliq/sdk` 0.7.1,
`beliq` 0.7.0) with two departures: the first attempt's own timeout is retried
as a request that may have run (the SDKs never retry their own timeout, but the
order request's 30 s can be shorter than beliq's own deadlines), and a TLS
failure is never retried (the Node SDK retries it by count):

- An outage a wait can outlast is tried at about 2, 5, 10, 14, 30 and 60
  minutes after the first attempt, or later when beliq's `Retry-After` asks for
  longer: a 503 `DATABASE_UNAVAILABLE`, a 502 or 503 without a beliq error code,
  and a connection that could not be opened.
- A failure that may have run at beliq is tried at most 3 times, and only within
  the first 15 minutes, however late the task runs: `ENGINE_UNAVAILABLE` or
  another coded 502 or 503, a 429 other than `QUOTA_EXCEEDED`, a connection lost
  after the request was sent, and the first attempt's own timeout. beliq does
  not charge a repeat of a request it charged in the last 15 minutes, with a key
  of the same mode, up to 10 repeats
  ([Repeated requests](https://docs.beliq.eu/api-reference/errors/#repeated-requests-are-not-charged-again));
  a later repeat of a request whose answer was lost would be charged again.
- The first attempt runs inside the state change with a 30 s timeout; the later
  attempts have the 90 s of the SDKs. A later attempt's own timeout is final,
  since beliq may still be working on the document.
- An outage failure after one that may have run (a lost connection, then a
  refused one, say) still gets the 30 and 60-minute slots. If the earlier
  request was charged and its answer lost, an attempt past 15 minutes that
  succeeds is charged again: one more unit, for an invoice that would otherwise
  not be made.
- A slot that passed while beliq asked for a longer wait, or while no task ran,
  is skipped rather than run straight after. A `Retry-After` that would push an
  attempt past the 60-minute slot ends the retries.
- Nothing else is retried: a 4xx would send the same order data to the same
  answer, and a TLS failure or a malformed base URL is not fixed by waiting. The
  failure stays in the order's custom fields, and a beliq e-invoice can be
  created by hand from the order's Documents tab.

The later attempts are rows in the plugin's `beliq_invoice_retry` table, worked
through by the scheduled task `beliq.invoice_retry` every minute. A shop that
runs no scheduled-task runner and no message consumer runs it only while
someone is logged into the Administration (Shopware's admin worker), so run
`bin/console scheduled-task:run` and `bin/console messenger:consume async` in
production, as Shopware's own documentation recommends. Each attempt advances
its row before it calls beliq, so a process killed mid-call leaves the next
attempt in place, and a claim is a conditional update, so two task runs never
attempt one order at once. A later attempt that stores the invoice clears the
failure. An invoice created by hand while an attempt is pending ends it: that
attempt finds the invoice, makes none (automatic generation never overwrites)
and clears the failure. An invoice created by hand after the last attempt
leaves the record of the last automatic failure. A deleted order takes its row
with it. The slots and timeouts are beliq's choice, sized from its own outages;
no standard sets them.

Uninstalling the plugin without keeping its data drops the table and the custom
field set.

## Requirements

- Shopware 6.7. 6.6 is not supported: the document renderer targets the 6.7
  `RenderedDocument` constructor, which dropped a leading parameter 6.6 still has.
- PHP 8.2 or newer with the `curl` and `json` extensions.
- A beliq account and API key. The free tier is enough to evaluate the plugin.

## Development

```bash
composer install
composer test          # PHPUnit
composer scrub:check   # fail on em-dash
shopware-cli extension validate . --store-compliance   # Store check, PHPStan at level 8
```

The mapper and client tests run without a Shopware install. The retry tests use
a real database (SQLite through DBAL) and, for the HTTP layer, a real local
server (PHP's built-in one). A live smoke against
`/v1/generate` and `/v1/validate` is gated on a `BELIQ_API_KEY` environment
variable.

## License

MIT. See [LICENSE](LICENSE).
