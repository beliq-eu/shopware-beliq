# Changelog

## Unreleased

- Framework-agnostic core: the order-to-EN 16931 mapper (VAT category derivation,
  tax breakdown, rounding, totals) and the beliq API client, with tests.
- Shopware runtime wiring: an `OrderEntity` -> `SourceOrder` adapter (net
  conversion from gross/net/tax-free orders, per-line VAT rate, buyer from the
  billing address and customer), a typed settings layer with a business-only
  scope, an order-state subscriber that generates on payment paid or order
  completed, and document storage as a private media file linked on the order.
  Admin settings via `config.xml`. Adapter and config mapping are unit tested.
