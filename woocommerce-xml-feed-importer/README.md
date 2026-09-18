# WooCommerce XML Feed Importer

## Structure

- `woocommerce-xml-feed-importer.php` - plugin bootstrap.
- `includes/class-wpfi-plugin.php` - service wiring and lifecycle.
- `includes/class-wpfi-feed-repository.php` - feed persistence.
- `includes/class-wpfi-scheduler.php` - WP-Cron schedules.
- `includes/class-wpfi-importer.php` - XML parsing and WooCommerce writes.
- `includes/class-wpfi-admin.php` - feed table, editor, actions, and log viewer.
- `includes/class-wpfi-logger.php` - persistent import logs and WooCommerce logger integration.
- `examples/supplier-feed.xml` - sample simple and variable-product XML.

## Feed editor format

Mappings, attributes, and namespace registrations use one entry per line:

```text
name=name
sku=sku
price=price
stock_quantity=stock
```

```text
Color=attributes/color
Size=attributes/size
```

```text
g=https://example.com/google-product-feed
```

Use the registered namespace prefix in XPath expressions, for example `//g:item` or `g:title`.

## Variable products

Set `Variation XPath` to a path relative to each product, such as `variants/variant`. Add attribute mappings such as `Color=attributes/color` and `Size=attributes/size`. The importer creates or updates the parent product and its WooCommerce variations using the variation SKU.

## Example configuration for the included XML

- Product XPath: `/products/product`
- Variation XPath: `variants/variant`
- Product mappings:
  - `name=name`
  - `sku=sku`
  - `description=description`
  - `price=price`
  - `stock_quantity=stock`
- Attributes:
  - `Color=attributes/color`
  - `Size=attributes/size`
- Namespace:
  - `g=https://example.com/google-product-feed`

The plugin uses WP-Cron. For reliable production scheduling, configure a real server cron to call `wp-cron.php` or use WP-CLI cron events.
