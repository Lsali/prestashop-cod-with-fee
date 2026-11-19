# Cash on Delivery with Fee (PrestaShop)

A PrestaShop payment module that adds a **Cash on Delivery (COD)** payment method with a configurable fee.

Compatible with **PrestaShop 1.7.6 – 9.x**.

---

## Features

- Configurable COD fee (tax included)
- Tax rule support for the fee
- Free COD fee above a products total threshold
- Minimum / maximum order total limits
- Custom order status when the customer places a COD order
- Customizable title & description on the checkout page
- **Disable COD for specific shipping methods (carriers)** — e.g. hide COD when the customer selects BOX NOW
- Fee is added as a hidden virtual product, so it appears as a separate line on the order, invoice and emails
- Live update of the order summary when the customer switches payment method (no full page reload)

---

## How the fee works

When the customer selects Cash on Delivery and places the order, the module adds a hidden virtual product called **COD Fee** to the order.  
This way the fee is visible as its own line item on:

- the order in the Back Office
- the invoice
- confirmation emails

You configure the fee amount and tax rule only from the module settings. Changes made directly on the product in the Catalog are overwritten on the next save.

---

## Configuration

Go to **Modules → Module Manager → Cash on Delivery with Fee → Configure**.

| Setting | Description |
|--------|-------------|
| **COD fee (tax included)** | Final amount the customer pays. Set to `0` for no fee. |
| **Tax rule for the fee** | Optional. If set, the tax-excluded price is calculated automatically. |
| **Free fee for orders above** | If products total (excl. shipping) reaches this amount → no fee. `0` = always charge. |
| **Minimum order total** | Below this total COD is hidden. `0` = no limit. |
| **Maximum order total** | Above this total COD is hidden. `0` = no limit. |
| **Order status** | Status assigned when the customer places a COD order. |
| **Disable for shipping methods** | Multi-select of carriers. COD will **not** appear when one of these carriers is selected. |
| **Checkout title** | Label shown in the payment methods list. |
| **Checkout description** | Text shown under the title. |

Countries, currencies and customer groups can still be restricted from **Payment → Preferences** (standard PrestaShop behaviour).

---

## Example use case

- COD fee = €3
- Available only for ELTA Courier
- Disabled for BOX NOW (and any other carriers you select)

→ When the customer chooses BOX NOW, Cash on Delivery does not appear among the payment options.

---

## Installation

1. Download or clone this repository.
2. Upload the `codwithfee` folder to your PrestaShop `/modules/` directory  
   **or** zip the folder and install it from **Modules → Module Manager → Upload a module**.
3. Install and enable the module.
4. Go to the module configuration and set your fee, limits and disabled carriers.
5. (Optional) Restrict countries / customer groups from **Payment → Preferences**.

---

## Requirements

- PrestaShop 1.7.6 or higher (tested up to 9.x)
- PHP 7.1+ (depending on your PrestaShop version)

---

## Changelog

### 1.3.0
- Added setting to disable COD for selected shipping methods (carriers)
- Translated module interface to English

### 1.2.1
- Initial public version with fee product, limits and live checkout update

---

## Author

levent sali

---

## License

This module is released under the [MIT License](LICENSE).
