# MB Max Limit - Advanced Quantity Rules

**Author:** Mohammad Babaei - https://adschi.com
**Compatibility:** PrestaShop 1.7.8+, PHP 7.2+
**Version:** 2.1.0

## Description

MB Max Limit is a powerful PrestaShop module for creating advanced, flexible rules to limit product purchase quantities. You can control how many items a customer can buy, both per-cart and over specific time periods, based on a wide range of conditions.

## Features

### Limit Types
-   **Per Product / Combination:** Set a specific max quantity directly on a product's edit page. Now supports setting different limits for each product combination (e.g., "T-Shirt, Red, L").
-   **Global Limit:** Set a default maximum quantity that applies to all products in your store unless a more specific rule is found.
-   **Advanced Rules Engine:** Create complex rules with the following scopes:
    -   Category
    -   Brand (Manufacturer)
    -   Country (based on delivery address)
    -   Customer Group
    -   **Product Feature:** Limit all products that share a specific feature (e.g., "Handmade").

### Limit Conditions
-   **Cart Limit:** Restrict the quantity of an item within a single shopping cart.
-   **Time-Based Purchase History Limit:** Restrict the total number of items a customer can purchase over a specific period. This is perfect for promotions or limited-stock items.
    -   **Time Frames:** All Time, Per Day (last 24h), Per Week, Per Month.
-   **Date Range & Day of Week:** Activate rules only for specific date ranges or on certain days of the week.
-   **Exclusions:** Exclude specific products or entire categories from advanced rules.

### Administrator Experience
-   **Redesigned UI:** A clean, panel-based configuration page for easy management.
-   **Bulk Actions:** Quickly add, update, or remove limits for all products within selected categories.
-   **AJAX Search:** Easily find categories, brands, features, etc., when creating rules.

### Customer Experience
-   **Remaining Quantity Display (Optional):** Show a helpful message on the product page, like "You can add 2 more of this item to your cart." This message updates dynamically when combinations are changed.
-   **Modal Error Popups (Optional):** Display a clean, user-friendly popup when a customer hits a limit, instead of the default PrestaShop notification.

## Installation

1.  Copy the `mbmaxlimit` folder into your PrestaShop `modules/` directory.
2.  In the Back Office, navigate to **Modules > Module Manager**.
3.  Search for "MB Max Limit" and click **Install**.
4.  If you are upgrading from a previous version, simply upload the new files. The module includes upgrade scripts that will handle database changes automatically when you refresh the module manager page.

## Usage

### Simple & Combination Limits
1.  Go to **Catalog > Products** and edit a product.
2.  **For products without combinations:** Go to the **Quantities** tab. You will find fields for "Maximum per cart" and "Enable max limit for this product".
3.  **For products with combinations:** Go to the **Combinations** tab. Expand a combination to find the same fields to set a limit for that specific variant.
4.  *Note: A combination-specific limit will always override a simple product limit.*

### Global & Advanced Rules
1.  Go to **Modules > Module Manager** and click **Configure** for the "MB Max Limit" module.
2.  **Global Settings:** Set a default limit for all products and configure the front-end display options.
3.  **Bulk Actions:** Use this form to apply or remove limits from products based on their category.
4.  **Advanced Rules:** Create detailed rules based on scope, time frames, and other conditions.

### Limit Precedence
The module always applies the **most restrictive** (lowest) limit found. The order of checking is:
1.  Per-Combination Limit
2.  Per-Product Limit
3.  Advanced Rules
4.  Global Limit

## Uninstall

Uninstalling the module from the Module Manager will remove all its database tables and configuration settings.

---
*This module was reviewed and enhanced by Jules, an AI Software Engineer.*
