# WooCommerce XML Feed Importer 3.1

The plugin imports simple and variable WooCommerce products from scheduled XML feeds. Each feed is configured independently under **WooCommerce → XML Feed Importer**.

## Customizable XML fields

The feed editor accepts one mapping per line in the form:

```text
WooCommerce field=XML selector
```

Example:

```text
name=name
sku=sku
description=description
short_description=short_description
price=price
sale_price=sale_price
stock_quantity=stock
stock_status=stock_status
image=image_url
category=category
```

Selectors can be simple child names (`name`), nested dot paths (`offer.price`), or XPath expressions (`offers/offer[1]/price`). The same mappings are used for product and variation nodes, so supplier field names remain fully configurable without changing PHP code.

## Variable products

Set **Variation XPath** to a path relative to the product node, for example:

```text
variants/variant
```

Add attribute mappings using the format:

```text
Color=attributes/color
Size=attributes/size
```

The importer will:

- create or update the parent as a variable product;
- create or update variations using the configured variation identifier, normally `sku`;
- apply price, sale price, stock, image, and custom attribute values to variations;
- create product attribute taxonomies using `pa_` slugs.

A feed without a Variation XPath is imported as simple products.

## XML namespaces

Register namespace prefixes as:

```text
g=https://example.com/google-product-feed
```

Then use the prefix in the product XPath or mapping selector, for example `//g:item` or `g:title`.

## Included sample

See `examples/supplier-feed.xml`. Suggested configuration for that file:

- Product XPath: `/products/product`
- Variation XPath: `variants/variant`
- Mappings: `name=name`, `sku=sku`, `price=price`, `description=description`, `stock_quantity=stock`
- Attributes: `Color=attributes/color`, `Size=attributes/size`

## Scheduling and logs

Feeds run through WP-Cron. The feed table provides **Run now**, **Edit**, and **Delete** actions. Import results and errors are available under **View import logs** and are also sent to the WooCommerce logger.
