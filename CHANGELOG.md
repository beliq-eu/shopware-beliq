# Changelog

## Unreleased

- The issue date (BT-2) of a beliq document is the day it is created, as a UTC
  day: the document date the Administration's create dialog sends, or the day
  of the automatic create, as on Shopware's own invoice. Up to 0.2.13 every
  beliq document carried the order date, also one created days after the
  order or created again. A `documentDate` that is no date fails the create
  before the generate call, so it does not count toward the beliq plan.
- A beliq document after the order's first names the invoice it replaces
  (BG-3): the order's latest earlier beliq invoice number (BT-25), also when
  that invoice's document was deleted, and its issue date (BT-26) when its
  document recorded one. Each beliq document records its issue date in its
  config, `beliqIssueDate`, from this version on, so an invoice made by 0.2.13
  or earlier is named without a date. Up to 0.2.13 a document created again did
  not refer to the earlier invoice. The invoice type (BT-3) stays 380: cancel
  the earlier invoice yourself.

## 0.2.13 (2026-11-25)

- Each amount the plugin sends to beliq is written as its shortest decimal
  (19.99 as 19.99), whatever the host's `serialize_precision`. Up to 0.2.12, on
  a host with `serialize_precision` set to 17, PHP's `json_encode` wrote 19.99
  into the request as 19.989999999999998. The plugin writes each float itself,
  as `json_encode` writes it at `serialize_precision` -1 (20.00 as 20), and
  leaves `serialize_precision` as the host set it.

## 0.2.12 (2026-11-24)

- A beliq document no longer takes a number issued before on its order. Up to
  0.2.11, after every beliq document of an order was deleted, the next one took
  the order number again as its document and invoice number (BT-1), although
  the buyer may hold an invoice with it. The plugin now records each number it
  issues in its own table, `beliq_invoice_number`, and counts those numbers on
  every order as it counts the documents. The update records the numbers of
  the beliq documents stored then; a number whose document was deleted before
  the update is not recorded. An uninstall that does not keep the plugin's
  data drops the table.
- Two creates for one order at once take two numbers. Up to 0.2.11 both could
  pick the same number, and the second failed with "Document number already
  exists" after its generate call had counted toward the beliq plan. The number
  is now picked under a per-order lock from Shopware's lock store (`LOCK_DSN`).
- The plugin's services are defined in `Resources/config/services.yaml`; the
  services and their wiring are unchanged. Shopware deprecates a plugin's
  `services.xml` from 6.7.14.0: up to 0.2.11 the plugin caused that deprecation
  notice, and with Shopware's `v6.8.0.0` feature flag turned on, Shopware
  refused the file with an error while building its service container. If you
  update by copying the new files over the old plugin folder, delete
  `src/Resources/config/services.xml` there: Shopware loads both files.

## 0.2.11 (2026-11-23)

- A 429 `DOCUMENT_CEILING_REACHED` is not tried again, the same as
  `QUOTA_EXCEEDED`. beliq answers it when an account with usage billing reaches
  its extra-document cap or the ceiling above it, and its `Retry-After` runs to
  the end of the billing window. Up to 0.2.10 it was retried as a failure that
  may have run, which in practice meant one retry only when the window turned
  within the first 15 minutes; any other time the first failure was already
  final. Now it is final every time, also just before the window turns: create
  the beliq e-invoice by hand from the order's Documents tab once the window has
  turned or the cap is raised. The beliq SDKs treat it the same way since
  `@beliq/sdk` 0.7.4 and `beliq` 0.7.2.

## 0.2.10 (2026-11-22)

- A beliq document created again by hand is a new invoice, and its invoice
  number (BT-1) is now its document number, `<order number>-<n>`. Up to 0.2.9
  every beliq document of an order carried the order number as its invoice
  number, so two documents with different content could carry one invoice
  number. The first document still carries the order number, and so does the
  next one after every beliq document of the order is deleted. The issue date
  and the payment reference stay as they were: the order date and the order
  number.
- The number of a new beliq document also skips the numbers of other orders'
  beliq documents. With dashes in the order numbers, another order's own
  number could have the regenerate form (order `A-1-2` next to order `A-1`),
  and the create failed with "Document number already exists".

## 0.2.9 (2026-11-22)

- Intra-community supply (`K`) is offered for "Tax-free orders to businesses
  in other EU countries", next to reverse charge (`AE`). Its invoice carries
  the country of the order's shipping address as the deliver-to country
  (BT-80, BR-IC-12), and as the actual delivery date (BT-72, BR-IC-11) the day
  the order's delivery was set to shipped, read from Shopware's state history
  (the later day, for a delivery shipped again after a cancel). A delivery set
  to shipped and later cancelled or returned still counts on that day. An
  order with a delivery never set to shipped, or whose deliveries were set to
  shipped on different days, gets no invoice, and the failure on the order
  says why.
- Two new settings hold the exemption reason for `K` (BR-IC-10). Without a
  reason the order gets no invoice and no beliq call is made, as for the other
  categories.
- The help text of "Tax-free orders to businesses in other EU countries" says
  how `K` works, in English and German.

## 0.2.8 (2026-11-22)

- An order Shopware marks tax free can get an invoice again, in the VAT
  category you state for where it ships. Two new settings, both set to no
  e-invoice by default, which keeps the 0.2.7 behaviour:
  - "Tax-free orders to businesses in other EU countries", for an order that
    ships to an EU country other than the seller's, to a buyer with a VAT ID:
    reverse charge (`AE`).
  - "Tax-free orders outside the EU", for an order that ships to a country
    Shopware does not mark as an EU member: export outside the EU (`G`) or not
    subject to VAT (`O`).

  The country of the order's shipping address decides, by its EU flag in
  Shopware. Every line and the shipping take the category at 0%. Any other
  tax-free order, such as one shipped within the seller's country, to an EU
  buyer without a VAT ID or to several countries, still gets no invoice, and
  the failure on the order says why. So does a tax-free order with no
  delivery, which Shopware creates for a cart of digital products only.
- Four new settings hold the exemption reason for `AE` (BR-AE-10) and `O`
  (BR-O-10). `G` uses the existing reason for `G`. Without a reason the order
  gets no invoice and no beliq call is made, as for `E` and `G`.
- An invoice in category `O` carries no VAT ID, neither the seller's nor the
  buyer's (BR-O-02), so it names the seller by the company registration number
  (BT-30, BR-CO-26). Without one the order gets no invoice.
- Intra-community supply (`K`) is not offered: its invoice needs the actual
  delivery date (BT-72, BR-IC-11) and the deliver-to country (BT-80,
  BR-IC-12), which the plugin does not send.
- The help texts of the setting for 0% lines and of the exemption reason for
  `G` say how tax-free orders are handled now, in English and German. The
  German help text of the setting for 0% lines also reads more naturally.

## 0.2.7 (2026-11-22)

- An order Shopware marks tax free gets no invoice. Shopware records that it
  charged no VAT, not why, so the plugin cannot tell which VAT category
  applies, such as intra-community supply (`K`), export outside the EU (`G`)
  or reverse charge (`AE`). The failure on the order says so, and no beliq call
  is made. Up to 0.2.6 such an order took the category set for 0% lines
  (`Z` by default), and in 0.2.6 with `E` or `G` also that category's
  exemption reason, so the invoice stated a VAT ground the shop had not.

## 0.2.6 (2026-11-21)

- 0% lines in VAT category `E` (exempt) or `G` (export outside the EU) now
  carry a VAT exemption reason, which EN 16931 requires for both (BR-E-10,
  BR-G-10). Four new settings hold a VATEX code (BT-121) and a text (BT-120)
  for each category, and the plugin sends what you enter on that category's
  VAT breakdown. Without a reason for the category in use, the order gets no
  invoice and no beliq call is made; the failure on the order names the
  category and the rule. Up to 0.2.5 these invoices went to beliq without a
  reason. Category `Z`, the default, is unchanged.

## 0.2.5 (2026-11-21)

- The German half of the Preview notice reads more naturally. The English half
  and what the Preview does are unchanged.

## 0.2.4 (2026-11-21)

- The Preview button of the admin's create-document dialog no longer calls
  beliq. It called generate, which counts toward the documents per month of
  the beliq plan, for a document that was never stored. The preview now runs
  the plugin's own checks only: an order the create would refuse shows the
  same error, and any other order gets a short text notice that says why there
  is no preview and asks to create the document instead.
- A document created again by hand takes one more than the highest number on
  the order (`10000-4` after `10000` and `10000-3`), where it took one more
  than the number of documents. After a merchant deleted a beliq document,
  that count could give a number still in use, and the create failed with
  "Document number already exists".

## 0.2.3 (2026-11-21)

- Reading a stored XML beliq invoice as PDF no longer calls beliq again. PDF
  is the default file type of Shopware's Admin API document download and of
  the Store API document route, and an XML document has no PDF file, so
  Shopware asked the plugin to render the document again. That made a second
  generate call, which counts against the beliq quota, and rewrote the stored
  document's number to the regenerate form (`10000-2`), after which creating
  the document again by hand failed with "Document number already exists". The
  read now gets no document, as for any file type a document does not have
  (the Admin API answers 204), and the stored document stays as it was. The
  admin's Documents card was not affected: it asks for the stored file's own
  type. A beliq document whose file was lost is not rebuilt either; create a
  new one by hand, which takes the next number.
- The Store descriptions in English and German and the README say that
  delivery through beliq is in development, next to what the plugin does
  today: it generates the document, which beliq checks, and does not send it.
  Before, they said only that sending and filing stay with the merchant, which
  read as if beliq would never deliver.

## 0.2.2 (2026-11-16)

- The Packagist description, the plugin descriptions in English and German and
  the README no longer call the invoices compliant ("konforme") or the rules
  authority-pinned. The README says beliq checks each document during
  generation against the rule packs and schemas of its format; the plugin has
  no separate validate step. No behaviour changed.

## 0.2.1 (2026-11-13)

- The shipping line takes the order's shipping method name, in the language the
  order was placed in, where it read `Shipping` on every order. The order is
  loaded with its own language first, as Shopware's own invoice does. An order
  whose deliveries that carry the shipping costs name no shipping method, or
  name different ones, gets no invoice and its failure names the missing value
  (an order the business-only setting skips is skipped first): every
  line needs an item name (BT-153, BR-25) and no specification prescribes one
  for shipping, so the plugin does not make one up.
- German admin texts: the German labels are written with umlauts and ß, every
  help text and every field label that had none has a German version, as do the
  document format, output and trigger options. The German texts use one word for
  each thing: "erstellen" for generating, as Shopware's own document card does,
  "Daten" for details, and "Ansprechpartner beim Verkäufer" for the seller
  contact (BG-6). The German Store description is written with umlauts, and it
  says "Übermittlung und Einreichung" where "Versand und Meldung" read as
  shipping goods. The VAT category options (Z, E, G) keep their English names.
- The contact e-mail help text said it is always the seller electronic address
  (BT-34). It is for XRechnung, ZUGFeRD and Factur-X; Peppol BIS takes BT-34
  from the VAT ID.
- The IBAN help text said that without payment fields XRechnung needs the bank
  details another way. Without an IBAN the invoice carries no payment
  instructions (BG-16), which XRechnung requires (BR-DE-1); the text now says
  so. The payment means help text named only the SEPA credit transfer under
  BR-DE-23-a; the rule binds both offered codes, 58 and 30.

## 0.2.0 (2026-11-07)

- An automatic invoice whose first attempt meets a beliq outage is tried again
  later. When beliq cannot be reached or answers that it is unavailable, the
  scheduled task `beliq.invoice_retry` tries again at about 2, 5, 10, 14, 30 and
  60 minutes, or later when beliq's `Retry-After` asks for it. A failure that may
  have run at beliq (the first attempt's own timeout included) is tried at most 3
  times and only within the first 15 minutes, however late the task runs, and a
  rejection is not retried. A `Retry-After` beyond the 60-minute slot ends the
  retries. An outage failure that follows one that may have run still gets the
  30 and 60-minute slots, which can cost one more unit if the earlier request
  was charged and its answer lost. The classes follow the beliq SDKs with two
  departures, the first attempt's own timeout and TLS failures; see the README's
  "When beliq is unavailable". The plugin adds the table `beliq_invoice_retry`, whose rows
  go with their order or sales channel when it is deleted.
- Every failure of the automatic invoice shows on the order, in the custom field
  set "beliq e-invoice" on its Details tab. Before, it went to the log only.
- A transport failure says where the request stopped (the connection could not
  be opened, the connect timed out, the request timed out, the connection was
  lost, TLS), and a beliq error carries the response's `Retry-After`.

## 0.1.0 (2026-11-05)

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
