# Changelog

## Unreleased

- Framework-agnostic core: the order-to-EN 16931 mapper (VAT category derivation,
  tax breakdown, rounding, totals) and the beliq API client, with tests.
- Shopware runtime wiring: an `OrderEntity` -> `SourceOrder` adapter (net
  conversion from gross/net/tax-free orders, per-line VAT rate, buyer from the
  billing address and customer), a typed settings layer with a business-only
  scope, and an order-state subscriber that generates on payment paid or order
  completed. Admin settings via `config.xml`. Adapter and config mapping are unit
  tested.
- First-class order document: the generated invoice is stored as a `beliq_invoice`
  Shopware document through `DocumentGenerator` (custom document type registered by
  a migration, plus a `BeliqInvoiceRenderer` that maps the order and calls the beliq
  API). It appears on the order's Documents tab, downloadable, XML or hybrid PDF.
- The `profile` field is omitted for `xrechnung` and `peppol-bis`, whose profile is
  fixed by the standard (sending one is a hard `422`).
