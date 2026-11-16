# YSRTech_Faire

Sell an OpenMage website's catalog on [Faire](https://www.faire.com), the wholesale marketplace,
through the Faire External API v2 (`https://www.faire.com/external-api/v2`). Settings per
website: each brand connects its own Faire account.

| | Direction | When |
|---|---|---|
| Products and prices | OpenMage → Faire | nightly, or "Send Products" |
| Stock levels | OpenMage → Faire | every 15 minutes |
| Orders | Faire → OpenMage, accepted on Faire | every 10 minutes |
| Cancellations | Faire → OpenMage | with the orders |
| Tracking numbers | OpenMage → Faire | every 20 minutes |

## Switches

Each direction has its own switch (per website): **Send Products**, **Send Stock Levels**,
**Import Orders**, **Send Tracking Numbers**. Off stops the scheduled runs and the buttons /
shell commands alike (a `--dry-run` still shows what would be sent).

## Currencies

Nothing is converted on the way to Faire:

- **Main prices**: "Prices from Store View": that website's prices, in its base currency
  (Slim Syrups CA: CAD), optionally limited to a "Price Region".
- **Prices per Region**: extra price lists, one per line, `REGION => store view code` with region
  `USA` or `EUROPEAN_UNION`. `USA => us_en` adds the US website's USD prices for US retailers,
  next to the CAD prices. Wholesale prices follow the same rules (product override, customer
  group, percentage) in each list.

Orders coming back carry the currency the retailer paid in: "Store Views by Currency"
(`USD => us_en`) places them in the store view of that currency at Faire's prices; other
currencies go to "Store View for Orders", converted with the currency rates.

## Products

Products with **Sell on Faire** = Yes (product page, Faire tab; or the Manage Products mass
action) that are enabled and visible. Simple products are one Faire variant; configurable
products one variant per child, with their options.

- Name, description and short description as plain text (paragraphs and lists kept as lines),
  original images (base image first, up to 10), country of manufacture, GTIN (attribute set in
  the settings), Faire product type (`tt_...`).
- **Wholesale price** per unit: "Faire Wholesale Price" on the product, else the price of a
  customer group (group / tier price), else a percentage of the retail price.
  **Retail price**: the price or the MSRP. Prices from a chosen store view (its website's prices
  and base currency).
- **Case size** and minimum order quantity (settings, or "Faire Case Size" on the product).
- New products are published, or created as drafts to review on Faire. Unchanged products are
  skipped. Products no longer sold are paused on Faire (`SALES_PAUSED`); Faire cannot delete
  through the API.

## Orders

- Imported when `NEW` or `PROCESSING`, from the "Import orders from" date, once per Faire order.
  Placed in the store view for their currency ("Store Views by Currency", e.g. `USD => us_en`),
  else the configured store view (amounts converted with the currency rates).
- Faire wholesale prices, payment "Faire", shipping "Faire", the configured customer group,
  optionally without tax (Faire handles it). Status Processing, no invoice, no email. The order
  comment has the Faire order number, payout, ship-after date, retailer PO and notes.
- Accepted on Faire automatically (expected ship date: N business days), unless turned off.
- An order that cannot be imported (unknown SKU, missing currency rate) is listed under
  Sales > Faire Orders with the reason and an "Import again" link.
- Canceled on Faire: the OpenMage order is canceled while not invoiced or shipped; otherwise
  it gets a comment.

## Shipments

Each tracking number added to a shipment of a Faire order is sent to Faire once
(`SHIP_ON_YOUR_OWN`), carrier mapped from the OpenMage carrier code (setting "Carriers"),
retried up to 5 times on errors.

## Setup

1. `composer require ysrtech/faire` (repository `https://github.com/ysrtech/YSRTech_Faire`).
2. System > Configuration > YSRTech > Faire, **website scope**: enable, paste the Faire access
   token (or OAuth app credentials), "Test Connection".
3. Set the Faire product type, case size and wholesale pricing; "Import orders from" = go-live
   date; Store Views by Currency.
4. Flag the products, "Send Products", check them on Faire, then "Send Stock".

Command line:

```bash
php shell/ysrtech_faire.php test --website slimsyrups
php shell/ysrtech_faire.php products --website slimsyrups --dry-run
php shell/ysrtech_faire.php all
```

Log: `var/log/ysrtech_faire.log`.
