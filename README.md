# shopware-beliq

A Shopware 6 plugin that turns store orders into compliant EN 16931 e-invoices
(XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the [beliq](https://beliq.eu)
API. beliq produces and checks the compliant document; transmission, archiving,
and tax-authority reporting stay with your access point.

## Status

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
runs weekly.

Verified end to end on a local Shopware 6.7 (Dockware) instance talking to a
local beliq API and engine (ROADMAP.md, passes 1c and 1e). A business order's
paid transition fired the subscriber and produced a `beliq_invoice` document
with its file on disk, for both XML and hybrid-PDF output. Generating again
from the admin produced a second document with its own number. Separately,
XRechnung and German Peppol BIS invoices built by the plugin's mapper and client
validated with zero errors against the same local API and engine
(`LiveGenerateSmokeTest`, which runs only when `BELIQ_API_KEY` is set).

Still open:

- The Dockware run has not been repeated against the production API
  (`api.beliq.eu`).
- The plugin is not published. The repository has no git tag, and Packagist
  builds its releases from tags.
- Non-standard VAT categories (reverse charge, intra-community, export) carry no
  exemption reason. The beliq API accepts one; the plugin does not send it.

See [ROADMAP.md](https://github.com/beliq-eu/shopware-beliq/blob/main/ROADMAP.md).

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
and intra-community supply are the merchant's call to configure; see
[ROADMAP.md](https://github.com/beliq-eu/shopware-beliq/blob/main/ROADMAP.md)
for why they are not auto-detected.

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
```

The mapper and client tests run without a Shopware install. A live smoke against
`/v1/generate` and `/v1/validate` is gated on a `BELIQ_API_KEY` environment
variable.

## License

MIT. See [LICENSE](LICENSE).
