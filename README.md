# shopware-beliq

A Shopware 6 plugin that turns store orders into compliant EN 16931 e-invoices
(XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the [beliq](https://beliq.eu)
API. beliq produces and checks the compliant document; transmission, archiving,
and tax-authority reporting stay with your access point.

## Status

The framework-agnostic core is implemented and tested: the order-to-EN 16931
mapper (VAT category derivation, tax breakdown, EN 16931 rounding and totals) and
the beliq API client. The Shopware runtime wiring is in place: an order-state
subscriber runs the mapper and stores the generated document, driven by admin
settings. The order-to-`SourceOrder` adapter and the config mapping are unit
tested; the end-to-end path (subscriber firing, media storage) is verified on a
running Shopware instance, which is the remaining smoke step. See
[ROADMAP.md](ROADMAP.md).

## What it does

- Reads a completed order and builds a valid EN 16931 invoice from its lines,
  taxes, parties, and totals.
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
and intra-community supply are the merchant's call to configure; see
[ROADMAP.md](ROADMAP.md) for why they are not auto-detected.

## Setup

In the plugin settings (Extensions -> beliq e-invoicing -> Configure):

- Enter your beliq API key and, if needed, a custom API base URL.
- Fill in the seller legal details (name, VAT ID, address). EN 16931 requires
  them, so the seller block is the merchant's to complete.
- Pick the document format (ZUGFeRD / Factur-X hybrid PDF by default), profile,
  and whether to output a hybrid PDF or XML only.
- Choose when to generate: on payment paid (default) or on order completed.
- Turn on `Generate invoices automatically`. Generation is off until you do.

The generated document is stored as a private media file and its id is recorded
on the order's `customFields` (`beliq_invoice_media_id`).

## Requirements

- Shopware 6.6 or newer.
- PHP 8.2 or newer with the `curl` and `json` extensions.
- A beliq account and API key. The free tier is enough to evaluate the plugin.

## Development

```bash
composer install
composer test          # PHPUnit
composer scrub:check   # fail on em-dash
```

The mapper and client tests run without a Shopware install. A live smoke against
`/v1/generate` and `/v1/validate` is gated on a `BELIQ_API_KEY` environment
variable.

## License

MIT. See [LICENSE](LICENSE).
