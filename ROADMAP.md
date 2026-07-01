# shopware-beliq roadmap

A Shopware 6 plugin that turns store orders into compliant EN 16931 e-invoices
(XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the beliq API. beliq produces
and checks the document; transmission, archiving, and tax-authority reporting
stay with the merchant's access point.

This is the reference PHP plugin. Once it is solid, the same core (the beliq
client + the order-to-EN 16931 mapper + its tests) ports to WooCommerce. Shopify
is a separate track, deferred until the PHP pair proves the mapping.

## Naming

- Repo / directory: `shopware-beliq` (matches the `<platform>-beliq` portfolio).
- Composer package: `beliq/shopware-beliq`.
- PHP namespace: `Beliq\Shopware\`.
- Plugin technical name (base class): `BeliqShopware`.
- Store display label: `beliq e-invoicing`.

## Locked decisions

1. **Shopware first, then port to WooCommerce, Shopify deferred.** Shopware and
   WooCommerce are self-hosted PHP plugins that call beliq from the merchant's
   server. Shopify is a hosted OAuth app (its own service, billing, App Store
   review); it waits until the PHP pair proves the mapping.
2. **Generation defaults to business orders, configurable.** Generate a
   structured invoice when the buyer looks like a business (VAT ID present, or
   the order flagged business); the merchant can widen to all orders. B2C party
   data from checkout is often too thin to form a clean invoice, and the mandate
   demand is B2B, so the safe default is narrow.
3. **The plugin generates and stores; it does not transmit.** Output is a
   compliant document attached to the order (Shopware document / media). Peppol
   transmission, email delivery, and filing stay with the merchant.
4. **Self-contained beliq client for now.** There is no PHP beliq SDK. Pass 1
   ships a small internal HTTP client. When the WooCommerce port lands (the
   second PHP consumer), decide whether to extract a shared `beliq-php` Composer
   package. WordPress.org distribution complicates shared Composer deps
   (vendoring / prefixing), so that extraction is a decision at the port, not now.
5. **Standard-rated (`S`) is the correct, tested path in Pass 1.** See the known
   limitation below on non-standard VAT categories.

## Known limitation: VAT exemption reasons

beliq's generate schema models `taxSummary` as `{vatCategoryCode, vatRate,
taxableAmount, taxAmount}` and lines carry no exemption-reason field. EN 16931
requires an exemption reason for non-standard categories (BR-AE-10 for reverse
charge, BR-IC-* for intra-community, BR-E/BR-G/BR-Z families). So reverse-charge,
intra-community, and export invoices cannot cleanly carry their required reason
through the current API.

Pass 1 therefore implements standard-rated (`S`) fully and correctly, and treats
a zero rate as a merchant-configured category (default `Z`) with a documented
caveat that the merchant owns the tax treatment. Auto-detecting reverse charge is
deliberately out of scope: getting it subtly wrong in a compliance tool is worse
than not doing it.

Follow-up (beliq-api, not this repo): add an optional `exemptionReasonCode` /
`exemptionReasonText` to `taxSummary` entries so non-standard categories become
expressible. Tracked as tobias-dev/bq-api#62. Until then the plugin surfaces
zero-rate orders for merchant review rather than silently emitting an invoice that
fails EN 16931 business rules.

## Passes

### Pass 1a: framework-agnostic core (this pass)

- `src/Invoice/*` value objects: the normalized order the mapper consumes
  (parties, address, lines, payment means).
- `src/Service/InvoiceMapper.php`: `SourceOrder` -> beliq generate body. Owns the
  real work: per-line VAT category derivation, the `taxSummary` breakdown grouped
  by category+rate, EN 16931 rounding and totals consistency (BR-CO-15/17),
  unit-code default.
- `src/Service/BeliqClient.php` + `HttpClient` seam: `generate`, `validate`,
  `me`; `X-API-Key` auth; binary-safe response; error mapping to
  `BeliqApiException`.
- `tests/`: PHPUnit. Mapper tests assert derived categories, tax grouping,
  rounding, totals, and B2B detection against fixtures (real assertions, not
  mocks returning themselves). Client tests use a fake HTTP sender to assert the
  built request and error mapping. A live smoke gated on `BELIQ_API_KEY` sends a
  mapped body to `/v1/generate` + `/v1/validate` and asserts the document
  validates.
- Repo skeleton: `composer.json` (type `shopware-platform-plugin`), plugin base
  class, `README`, `LICENSE`, `.gitignore`, `phpunit.xml`, scrub check, CI.

Verified locally with PHP 8.5 (Composer absent; PHPUnit run via `phpunit.phar`).

### Pass 1b: Shopware runtime wiring (done, with a smoke gate)

Built against the real `shopware/core` (verified against v6.7.x, which satisfies
the `^6.6` constraint): order entity graph, the state-transition event, the config
XSD, and the media subsystem.

CI-verified (unit tests construct real Shopware entities and assert behaviour):

- `src/Service/OrderAdapter.php`: `OrderEntity` -> `SourceOrder`. Reads line items
  and their `CalculatedTaxCollection`, converts gross/net/tax-free figures to the
  net basis EN 16931 needs, derives the per-line VAT rate, and builds the buyer
  `Party` from the billing address + `orderCustomer` (VAT ids, company, account
  type drive the business signal). This is the Shopware-specific extraction; the
  mapper stays platform-agnostic.
- `src/Config/PluginConfig.php` + `PluginConfigProvider`: the typed settings DTO
  (including the assembled seller `Party`) and the mapping from raw config values,
  with defaults and coercion. `PluginConfig::allowsOrder()` is the business-only
  gate.

Wired against real APIs, lint-clean, but gated on a running-Shopware smoke (they
cannot be exercised without a kernel + DB):

- `src/Subscriber/OrderStateSubscriber.php`: subscribes to
  `state_enter.order_transaction.state.paid` and `state_enter.order.state.completed`,
  filters to the configured trigger, runs adapter -> business gate -> mapper ->
  beliq client -> document store. Never throws out of the handler (a generation
  failure must not break checkout); failures are logged.
- `src/Service/DocumentStore.php`: persists the generated bytes as a private media
  file via `MediaService::saveFile` and records the media id on the order's
  `customFields`.
- `src/Resources/config/config.xml`: API key, base URL, seller legal details,
  target format / profile / output, generation scope (business-only default),
  trigger state, zero-rate category.
- `src/Resources/config/services.xml`: DI wiring.

### Pass 1c: runtime smoke + first-class order document (needs a local Shopware)

- Install into a Shopware 6.6+ instance and smoke the full path: place a B2B order,
  transition it to paid, confirm the media file is written and linked on the order.
- Promote storage from a `customFields` media reference to a first-class order
  document (custom document type + `DocumentGenerator`) if the smoke shows the
  media-only linkage is not discoverable enough in the admin.
- Admin action to (re)generate for a single order.
- Store submission is a separate operator step (below).

## Operator-gated (post-go-live)

- Shopware Store (Community Store) producer account + manual review submission,
  and/or Packagist listing. Needs a live beliq API for review screenshots and a
  test store. Documented here; not part of the code build.
- Live-key smoke once a `BELIQ_API_KEY` and live/staging API exist.

## Conventions

- GitHub `beliq-eu`; commit identity `beliq <hello@beliq.eu>`; push pinned with
  `GH_TOKEN=$(gh auth token --user beliq-eu)`; active gh back to `tobias-dev`.
- No em-dash, no buzzwords; comments and docs describe present state.
- Copy stays on "generate / validate a compliant document," never
  "send / file / submit."
