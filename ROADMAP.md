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
6. **The version stays `0.1.0` until the publish pass, which is where `1.0.0`
   gets decided.** Every published beliq connector is 0.x (n8n 0.2.0,
   activepieces 0.2.1, directus 0.2.3, beliq-mcp 0.3.1, beliq-cli 0.2.1,
   beliq-sevdesk 0.2.1, `@beliq/sdk` 0.3.1, `beliq` on PyPI 0.2.1), and beliq
   itself has not gone live, so a 1.x plugin would be the only 1.x thing in the
   portfolio and would claim more than the product does. Nothing is pinned to
   the number yet: no git tag, no Packagist listing, no Store listing. The
   publish pass already reopens the version metadata (the changelog date, the
   live-key smoke, `Tested up to`), so promoting to `1.0.0` there costs the same
   as it does now and can be decided against a live API. Do **not** ship a Store
   ZIP whose `--overwrite-version` differs from `composer.json`: the listing and
   the changelog heading would disagree.

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

The beliq API now accepts an optional `exemptionReasonCode` / `exemptionReasonText`
on `taxSummary` entries (tobias-dev/bq-api#62), so non-standard categories are
expressible end to end. The plugin does not yet populate them: it still ships the
standard-rated path and treats a zero rate as a merchant-configured category. Wiring
a per-category exemption reason through `PluginConfig` and the mapper is a follow-up
(see Pass 1c findings).

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
  filters to the configured trigger, and delegates to `DocumentGenerator` (Pass 1c;
  the adapter -> business gate -> mapper -> beliq client path moved into the
  renderer). Never throws out of the handler (a generation failure must not break
  checkout); failures are logged.
- `src/Resources/config/config.xml`: API key, seller legal details,
  target format / profile / output, generation scope (business-only default),
  trigger state, zero-rate category.
- `src/Resources/config/services.xml`: DI wiring.

### Pass 1c: runtime smoke + first-class order document (needs a local Shopware)

Smoke run against a local Dockware Shopware 6.7 (PHP 8.3) with the plugin installed
and activated, pointed at a local beliq API + engine. A real B2B order (company +
VAT id) is placed through the Store API and its transaction transitioned to paid
through the Admin API, firing `state_enter.order_transaction.state.paid`.

What the smoke proves: the DI wiring resolves, the paid transition fires the
subscriber, and the full path (reload order -> adapter -> mapper -> beliq API ->
DocumentGenerator) produces a first-class order document. With a `zugferd` /
`en16931` document the engine generates and validates a green EN 16931 document,
which lands as a `beliq_invoice` document on the order with its media file written
to disk (verified for both the XML and the hybrid-PDF output).

Storage is a first-class order document. Generation runs through `DocumentGenerator`:
a custom `beliq_invoice` document type (registered by a plugin migration) and a
`BeliqInvoiceRenderer` that reloads the order, applies the business-only gate, maps
the order, calls the beliq API, and returns the bytes as the document media. The
subscriber shrank to a delegation: config/trigger gate, then
`documentGenerator->generate('beliq_invoice', ...)`. It still swallows and logs any
failure so a generation error never breaks the transition.

Why `DocumentGenerator` and not a direct `MediaService::saveFile`: on the paid
transition the media write happens inside a request routed through Symfony's
HttpCache, where the media subsystem only finds the row it just created if the write
runs in `Context::SYSTEM_SCOPE`. A bare `saveFile` in the subscriber failed with
"media not found"; `DocumentGenerator` wraps its media write in `SYSTEM_SCOPE`, so
the file persists. (Confirmed empirically: the same `saveFile` works from the CLI
and inside a plain DB transaction, and starts working over HTTP once wrapped in
`SYSTEM_SCOPE`.)

Buyer address (fixed in the prior pass): the order on the state-change event carries
only a shallow association set, so the renderer reloads the order with
`billingAddress.country`, `lineItems`, `orderCustomer.customer`, and `currency`
before mapping.

Profile omission for standards that pin their own: `PluginConfig::effectiveProfile()`
returns null for `xrechnung` / `peppol-bis`, so the `profile` field is dropped from
the generate body for those standards (sending `profile=en16931` there is a hard
`422`). The profile option still applies to the ZUGFeRD / Factur-X family.

Regeneration idempotency is handled in Pass 1e. Store submission is a separate
operator step (below).

### Pass 1d: XRechnung BR-DE field mapping (done)

XRechnung now validates green end to end. The fields BR-DE adds beyond a bare
EN 16931 body are mapped:

- **Seller contact (BG-6):** `sellerContactName` (BT-41, BR-DE-5) and `sellerPhone`
  (BT-42, BR-DE-6) config fields join the existing `sellerEmail` (BT-43, BR-DE-7) on
  the seller `Party`.
- **Payment means (BG-16):** a `paymentMeansCode` (58 SEPA credit transfer, default,
  or 30 credit transfer) plus `sellerIban` / `sellerBic` / `sellerBankName`. The
  payment means is assembled only when an IBAN is set, since BR-DE-23-a rejects a
  code-58 transfer with no IBAN and a transfer with no account is not payable. The
  order number becomes the payment reference (BT-83).
- **Buyer reference (BT-10, BR-DE-1):** the adapter fills it from the order custom
  field `beliq_buyer_reference` (a public-sector buyer's Leitweg-ID), falling back to
  the buyer's customer number, then the order number, so it is always present.
- **Electronic addresses (BT-34 / BT-49):** already produced by the engine, so no
  plugin change was needed. For CII (XRechnung) the engine derives them from each
  party's email (scheme `EM`); for UBL (Peppol BIS) from the party VAT id. The
  adapter already carries the buyer email and VAT id, and the seller email / VAT id
  come from config.

Verified with a gated live smoke (`LiveGenerateSmokeTest`, run with `BELIQ_API_KEY`
+ `BELIQ_BASE_URL`): a German B2B order maps to `xrechnung`, generates, and validates
green (zero errors) against the local api + engine.

**German Peppol BIS.** A German (DE to DE) Peppol BIS invoice must carry the seller
contact group (BG-6): `DE-R-002` requires the group and `DE-R-005`/`DE-R-006`/`DE-R-007`
its name (BT-41), telephone (BT-42), and email (BT-43). The plugin sends the seller
contact, and `beliq-engine` emits it as `cac:Contact` in the UBL output
(`tobias-dev/bq-engine#119`), so a German Peppol BIS invoice validates green once that
engine build is live on the target API. XRechnung (CII) carries the same contact and is
the other supported German target.

### Pass 1e: regeneration idempotency + German Peppol smoke (done)

The admin can already (re)generate a beliq invoice from an order's Documents card:
`beliq_invoice` is a registered document type with a global base config, so it appears
in Shopware's native "create document" dropdown alongside invoice / credit note. No
custom admin UI is needed. What was missing is idempotency, because the renderer pins
the document number to the order number and Shopware rejects a duplicate.

`InvoiceDocumentLookup` counts the beliq invoices already on an order, and two callers
use it:

- **Automatic generation never overwrites.** `OrderStateSubscriber` skips when a
  beliq invoice already exists, so a re-fired paid / completed transition is a no-op
  instead of a "document number already exists" error.
- **Manual regeneration gets a distinct number.** `BeliqInvoiceRenderer` uses the order
  number for the first document and `{orderNumber}-{n+1}` for a regenerate, so
  generating again from the Documents card succeeds and each document keeps a unique,
  order-derived number.

Verified end to end against the running Dockware 6.7 + local api + engine: an order that
already had `beliq_invoice` 10010 returned `DOCUMENT__NUMBER_ALREADY_EXISTS` before, and
after the change the same admin create call succeeds with document number `10010-2`.

German Peppol BIS is now covered too. The plugin's real `InvoiceMapper` + `BeliqClient`
produce a DE-to-DE Peppol BIS invoice that validates green (0 errors, `cac:Contact`
present) against the local engine carrying `bq-engine#119`. `LiveGenerateSmokeTest` gains
`testPeppolBisFromAGermanBusinessOrderValidatesGreen` next to the non-German case.

### Pass 1f: Shopware Store validation metadata (done)

`shopware-cli extension validate` is the tool the producer upload path runs, and it
reported eight errors, every one of them metadata:

| Error | Fix |
|---|---|
| `metadata.version` (twice: missing key, empty version string) | `"version": "0.1.0"` in `composer.json`. The ZIP carries no version anywhere else. |
| `metadata.description` en-GB 121 chars, de-DE 129 | each locale extended into the required 150 to 185 window |
| `metadata.manufacturer` / `metadata.support` | `de-DE` entries added; both locales now point at the locale's own page (`/` and `/de/`) |
| `metadata.icon` (twice) | `src/Resources/config/plugin.png` added |

The icon is the three-ring mark from `beliq-landing/public/beliq-logo-3ring-only.svg`
on `favicon.svg`'s white rounded tile, rendered at 256 px. The enforced window is 112
to 256 px and under 30 KB: a straight RGBA render is 32 KB and fails, so it ships as a
64-entry palette at 4.7 KB, which two colours plus antialiasing survive intact.

Every rule was falsified against the tool before being trusted: a 96 px icon, a 512 px
icon, a 230 KB icon and a 123-char description each produce their error. **The one rule
that does not exist is the changelog.** Deleting `CHANGELOG.md` outright leaves the run
clean on 0.18.3, as does a heading naming a different version, so the versioned-heading
requirement is a Store backend rule rather than a CLI one. The heading still moves from
`Unreleased` to `0.1.0`, because that is what the Store release notes read.

`extension package` builds `BeliqShopware-<ref>.zip` (42 KB, 29 entries, tests and
`vendor/` excluded, `plugin.png` present), and the extracted artifact validates with 0
errors.

Left as a warning, not an error: `config.services_xml.deprecated` asks for
`services.yaml`. `shopware-cli extension fix` converts it.

### Pass 1g: the Store gate runs in CI (done)

Pass 1f cleared `shopware-cli extension validate` and nothing kept it clear.
`ci.yml` gains a `store-validate` job, and `store-currency.yml` runs the same
command weekly.

`extension validate` **exits 1 on an error and 0 on a warning**, so the step is
the verdict and needs no output parsing. Worth stating because the obvious local
reading is wrong: piping it through `tail` returns `tail`'s status, which is how
an earlier run of this same command read as `exit=0` while printing
`ERROR found errors`. Falsified both ways against a real tree: clean exits 0 with
the `services.xml` warning still present, and deleting `plugin.png` exits 1.

**The CLI is deliberately unpinned** (`version: latest`). A pinned copy of the
rules can pass here and still be rejected on upload, which is the worse failure:
a false green. A new rule reddening the job is the gate reporting that the
Store's rules moved.

That makes the job's input include "what shopware AG released", which is not this
commit, so a push trigger alone is not enough. The plugin is dormant until beliq
go-live, so without a schedule a new rule would first surface at upload time,
months from now. `store-currency.yml` runs the same command weekly, and runs the
command itself rather than a second copy of the rule so the two cannot drift.
Scheduled fires slip (GitHub delays daily crons by 11 to 13 hours in measured
cases); weekly is sized for that. A failed scheduled run notifies the account
that last touched the cron, which is the only thing watching it.

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
