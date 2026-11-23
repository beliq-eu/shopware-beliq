# Live smoke (Dockware Shopware 6.7)

Runs the plugin inside a real Shopware store and checks every document it
stores. The path under test:

```
Store API checkout -> Admin API: transaction to paid
  -> OrderStateSubscriber -> DocumentGenerator -> BeliqInvoiceRenderer
  -> /v1/generate -> beliq_invoice document on the order
```

The transition runs inside a real Shopware request. That is where the media
write fails unless it runs in `Context::SYSTEM_SCOPE`, which unit tests can't
show.

Cases: German XRechnung (xml), French Peppol BIS (xml), German ZUGFeRD
(hybrid pdf) and a German XRechnung whose 0% lines are VAT exempt (category
`E`, with an exemption reason set). Each one downloads the stored document
through the Admin API and posts its bytes to `/v1/validate`. A stub run checks
the generate request instead: the exempt case's VAT breakdown carries the
configured reason, and the others carry none. More cases:
- a consumer order is skipped under the business-only setting;
- an exempt order with no exemption reason set gets no document, a failure
  on the order that names category `E` and BR-E-10, and no generate call;
- an order Shopware records as tax free (an Austrian company buyer with a VAT
  ID, under Austria's company tax setting) gets no document while the setting
  for tax-free orders to EU businesses is left at no e-invoice: a failure on the
  order names that setting, and there is no generate call;
- the same Austrian order, with reverse charge (`AE`) and its reason set, gets
  one document that validates; a stub run checks that every line is `AE` at 0%
  with one `AE` breakdown carrying the reason;
- the same Austrian order, with intra-community supply (`K`) and its reason
  set, gets no document when it is paid before it ships, and a failure on the
  order names the actual delivery date (BT-72, BR-IC-11); once its delivery is
  set to shipped, a document created by hand validates, and a stub run checks
  that every line is `K` at 0% and that the request carries the shipping day
  and `AT` as its delivery;
- a tax-free order from a Swiss company buyer (under Switzerland's company tax
  setting, with Switzerland added to the sales channel), with not subject to
  VAT (`O`), its reason and the seller's registration number set, gets one
  document that validates; a stub run checks that every line is `O`, the
  breakdown carries the reason, and neither party carries a VAT ID;
- a second paid transition stores no second document;
- creating the document by hand adds one numbered `<order>-2`, and its
  invoice number is `<order>-2` too (a stub run reads the request, a run
  against the API the stored XML);
- after `<order>-3` is added and `<order>-2` deleted, a create by hand
  succeeds as `<order>-4`;
- another order renumbered `<order>-5` takes that number for its own
  document, and a create by hand on the first order succeeds as `<order>-6`,
  with that invoice number;
- after every beliq document of a new order is deleted, a create by hand
  succeeds as `<order>-2`, with that invoice number; once `<order>-2` is
  deleted too, another order renumbered `<order>-2` takes `<order>-2-2`;
- two creates by hand at once on a new order both succeed, as `<order>-2`
  and `<order>-3`. Only a stub run makes the two overlap for sure: it delays
  each generate answer by 2 s. Against the API the case passes without the
  lock whenever the second create starts after the first is stored;
- the admin's Preview returns the plugin's local notice, stores nothing and,
  in a stub run, makes no generate call.

## Run

Needs Docker and PHP on the host (the host PHP runs only the stub).

```bash
BELIQ_API_KEY=... BELIQ_BASE_URL=https://api.beliq.eu ./run.sh
```

`BELIQ_BASE_URL` is resolved inside the container, so an API on this machine is
`http://host.docker.internal:<port>`. One run makes 11 generate and 6 validate
calls with the key.

```bash
./run.sh stub    # the store side only, no key, no quota
./run.sh down    # remove the container and its volumes
```

The stub run answers every call from `stub-router.php`, so the documents it
stores are placeholders that no validator read. A green stub run says the
Shopware side works, nothing more.

The store stays up after a run at http://localhost:8090/admin (admin /
shopware). `run.sh` copies the files a merchant installs (the set
`.gitattributes` leaves in the archive) from the working tree into
`custom/plugins` and reinstalls the plugin on every run, so uncommitted changes
are tested too.
