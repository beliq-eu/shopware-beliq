# Changelog

## 0.1.0

- Requires Shopware 6.7 (`shopware/core` `~6.7.0`). The plugin declared `^6.6`
  but was only ever tested on 6.7, and every 6.6 release fails on the first
  document render: 6.7 dropped the leading `$html` parameter of
  `RenderedDocument`, so the arguments land one slot off on 6.6 and the config
  array meets a string parameter.
- Shipping is invoiced. Shopware keeps it on the order rather than among the
  line items, so the invoice left it out and its totals understated what the
  customer paid while still validating. It is now its own line, one per VAT rate
  when the shipping method spreads its tax across the cart's rates; free shipping
  adds nothing.
- Totals sum the line nets as emitted. Each line net is rounded once and every
  sum is taken over the rounded values, so the invoice's line total (BT-106) and
  each VAT group's taxable amount equal the sum of the lines (BR-CO-10, BR-S-08).
  Rounding the unrounded sum instead could land a cent away whenever a line net
  carried more than two decimals.
- The Output setting resolves to XML on XRechnung and Peppol BIS. Neither has a
  hybrid PDF, so the API answered `output=pdf` for them with a 400 on every
  order. The setting's own label ("PDF (hybrid, where the format supports it)")
  already said this is what it means.
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
