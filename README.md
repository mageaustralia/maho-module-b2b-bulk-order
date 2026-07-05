# MageAustralia B2B Bulk Order

Storefront bulk-order page for B2B customers.

> **Status:** v0.1 - storefront page + CSV template + parse + upload +
> add-to-cart + tax-display all working. Send-as-Quote button turns on
> when the companion `maho-module-b2b-rfq` module is installed.

## What ships

- **Storefront route** at `/bulk-order/` (frontend controller,
  `MageAustralia_B2bBulkOrder_IndexController`).
- **`GET /bulk-order/index/template/`** - downloads a starter CSV
  (`SKU,Quantity`) with a hash-prefixed instruction block; commented lines
  are ignored on re-upload.
- **`POST /bulk-order/index/parse`** - parse a paste-in textarea of SKUs
  (formats accepted: `SKU`, `SKU,qty`, `SKU qty`, `SKU\tqty`). Returns
  `{matched: [{sku, name, qty, unitPriceInc, unitPriceEx, productId, url}...],
  unmatched: [sku, ...]}` as JSON so a headless storefront can render inline
  results without a full reload.
- **`POST /bulk-order/index/upload`** - identical shape, but reads a CSV
  file upload (field: `file`). Header row auto-detected. `#`-prefixed lines
  skipped. Row limit enforced via `b2bbulkorder/general/max_rows`.
- **`POST /bulk-order/index/add-to-cart`** - batch add all the matched
  lines. Redirects to `checkout/cart`.
- **`POST /bulk-order/index/set-tax-display`** - toggles the customer's
  session-scoped ex-tax / inc-tax display mode.
- **Helper API** for other modules:
  - `MageAustralia_B2bBulkOrder_Helper_Data::resolveSkus(array $skus)`
    returns `matched` + `unmatched`. Case-insensitive SKU matching to
    handle spreadsheet-drift.
  - `parsePastedLines(string $text): array<string, int>` handles all four
    accepted line formats.
  - `getTaxDisplayMode(): int` reads the customer session; `setTaxDisplayMode(int)`
    persists it.

## Admin config

*System, Configuration, Customer, B2B Bulk Order* - defaults:

| Setting | Default | Notes |
|---|---|---|
| Enabled | Yes | Master switch. |
| Require login | Yes | Guests get redirected to /customer/account/login (with `bulk-order` as return-to). |
| Allowed customer groups | `0,1,2` | Comma-separated group ids. Empty = any group. |
| Default tax display | Ex tax (0) | Buyer can flip via the toggle; choice persists per session. |
| Max rows per upload | 500 | Guardrail against overlarge CSV uploads. |

## Send-as-Quote button

The bulk-order grid template includes a *"Send as Quote request"* submit
alongside *"Buy Now - add all to cart"*. The Send-as-Quote button is only
rendered if the `mageaustralia/maho-module-b2b-rfq` module is installed;
if it isn't, the button is hidden and the grid only offers the Buy-Now
path.

That composition is deliberate - this module has zero code-level
dependency on the RFQ module. The RFQ module registers `/rfq/index/create`
which reads the same `sku[]` / `qty[]` POST shape; installing it turns the
button on.
- Backend endpoints (template download, parse, upload, add-to-cart) all
  work, so a merchant can use this module today by wiring their own
  storefront button to the endpoints.

## Install

```bash
composer require mageaustralia/maho-module-b2b-bulk-order
./maho cache:flush
```

## License

OSL-3.0.
