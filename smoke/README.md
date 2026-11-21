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
- a second paid transition stores no second document;
- creating the document by hand adds one numbered `<order>-2`;
- after `<order>-3` is added and `<order>-2` deleted, a create by hand
  succeeds as `<order>-4`;
- the admin's Preview returns the plugin's local notice, stores nothing and,
  in a stub run, makes no generate call.

## Run

Needs Docker and PHP on the host (the host PHP runs only the stub).

```bash
BELIQ_API_KEY=... BELIQ_BASE_URL=https://api.beliq.eu ./run.sh
```

`BELIQ_BASE_URL` is resolved inside the container, so an API on this machine is
`http://host.docker.internal:<port>`. One run makes 7 generate and 4 validate
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
