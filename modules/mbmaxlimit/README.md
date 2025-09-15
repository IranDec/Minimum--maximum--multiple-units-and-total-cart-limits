# mbmaxlimit (Maximum per-product cart limit)

Author: Mohammad Babaei - https://adschi.com

Compatibility: PrestaShop 1.7.8.x, PHP 7.2+

## Features
- Set a per-product maximum quantity per cart from the product edit page
- Enforce limits during add-to-cart and cart quantity updates
- Module configuration page shows all limited products with quick links to edit
- Advanced rules by scope: Category, Brand (Manufacturer), Country (delivery), Customer Group
- Most restrictive max among applicable rules is enforced
- Persian (fa) and English (en) translations, Bootstrap UI, author branding on settings page

## Installation
1. Copy the `mbmaxlimit` folder into your PrestaShop `modules/` directory
2. In Back Office -> Modules, search for "Maximum per-product cart limit" and install
3. Clear cache if needed (Advanced Parameters -> Performance -> Clear cache)

## Usage
### Per-product limit
- Catalog -> Products -> Edit a product
- In the form, set:
  - "Maximum per cart": maximum allowed units in a single cart (0 = no limit)
  - "Enable max limit for this product": toggle activation
- Save the product

### Advanced rules
- Modules -> mbmaxlimit -> Configure
- In the "Advanced rules" section:
  - Scope: Category, Brand, Country, Customer group
  - Target ID: the corresponding ID (e.g., category ID, manufacturer ID, country ID, or customer group ID)
  - Max per cart: maximum allowed units (0 = no limit; ignored if 0)
  - Active: enable/disable the rule
- You can list and delete/toggle rules from the same page

### Conflicts and precedence
- The module computes the effective max as the minimum of all applicable positive limits:
  - Per-product limit (if enabled)
  - All matching advanced rules (category/brand/country/customer group)
- If no applicable positive limit exists, no restriction is applied

## Uninstall
- Uninstalling drops module tables:
  - `ps_mbmaxlimit_product`
  - `ps_mbmaxlimit_rule`

## Notes
- Error shown to customers when they exceed the limit during add-to-cart/cart update
- Delivery country is determined from the cart delivery address
- Brand refers to Manufacturer in PrestaShop data model

## Support and credits
- Author: Mohammad Babaei - https://adschi.com
- Module scaffolding and implementation: custom-built for your shop
