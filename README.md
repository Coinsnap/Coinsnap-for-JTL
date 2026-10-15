# Coinsnap for JTL-Shop

![Coinsnap for JTL Payment](https://resources.coinsnap.org/products/jtl-store/images/screenshot-0.png)

**Bitcoin payment plugin for JTL-Shop — accept Bitcoin and Lightning with Coinsnap.**

Coinsnap for JTL-Shop is an open-source payment plugin that adds Bitcoin payments to JTL-Shop and integrates them into the existing checkout and order workflow.

Customers can pay with **Bitcoin via Lightning or on-chain**. When using Coinsnap, merchants do not need to operate their own Bitcoin or Lightning payment infrastructure.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

## Key features

- Accept **Bitcoin and Lightning payments** in JTL-Shop
- Support for **Lightning and Bitcoin on-chain**
- Native integration into the JTL-Shop checkout
- Payment before order completion
- No own Lightning node or Bitcoin payment server required
- **Non-custodial / self-custody** approach
- Settlement to a compatible Bitcoin or Lightning wallet
- Open source under the GPL-2.0 License

## Requirements

The current plugin version is **1.0.3**.

According to the plugin metadata, the minimum supported JTL-Shop version is **5.2.3**.

The plugin requires:

- **JTL-Shop 5.2.3 or newer**
- **PHP 8.1 or newer**
- A **Coinsnap merchant account**

For Bitcoin settlement, you can use a compatible Bitcoin or Lightning wallet. One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/), which combines a self-custodial Bitcoin wallet with merchant and point-of-sale functionality.

## How it works

1. Create a [Coinsnap merchant account](https://app.coinsnap.io/register).
2. Download the Coinsnap for JTL-Shop plugin from this repository.
3. Upload and install the plugin in the JTL-Shop plugin manager.
4. Open the Coinsnap payment-method settings.
5. Enter your Coinsnap **Store ID** and **API key**.
6. Enable the Coinsnap payment method.
7. Perform a test transaction before going live.

## Installation

### 1. Download the plugin

Download the source code from this GitHub repository and extract it locally.

Create a ZIP archive containing the plugin folder with the exact name:

`jtl_coinsnappayment`

### 2. Upload the plugin

In the JTL-Shop administration:

1. Open the **Plugin Manager**.
2. Go to the **Upload** tab.
3. Upload the ZIP archive.
4. Select **JTL Coinsnap Payment**.
5. Install and activate the plugin.

### 3. Configure the payment method

1. Go to the JTL-Shop payment-method settings.
2. Find **Coinsnap Payment**.
3. Open the settings.
4. Enter your **Store ID**.
5. Enter your **API key**.
6. Configure the payment method name and other available options.
7. Save the settings.

Your Store ID and API key are available in your Coinsnap merchant account.

## Resources

- [Coinsnap JTL-Shop demo store](https://jtl.coinsnap.org/)
- [Coinsnap for JTL-Shop article](https://coinsnap.io/shop-systems/jtl-store/)
- [Coinsnap Wallet](https://coinsnap.io/wallet/)
- [Coinsnap API documentation](https://docs.coinsnap.io/)
- [Coinsnap merchant account](https://app.coinsnap.io/register)
- [Coinsnap website](https://coinsnap.io/)
- [Telegram community and support](https://t.me/coinsnap)

> **Brand note:** Coinsnap is Bitcoin payment software for merchants and is not the provider of the similarly named coin identifier app "CoinSnap".

## Frequently Asked Questions

### What are the requirements for accepting Bitcoin in my JTL-Shop?

You need JTL-Shop 5.2.3 or newer, PHP 8.1 or newer, and a Coinsnap merchant account. You do not need to operate your own Lightning node or Bitcoin payment server.

### Can customers pay with both Lightning and Bitcoin on-chain?

Yes. Coinsnap supports Bitcoin payments via both the Lightning Network and Bitcoin on-chain.

### Which Bitcoin or Lightning wallets can I use?

You can use a compatible Bitcoin or Lightning wallet for settlement. One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Other compatible wallets may include solutions such as Wallet of Satoshi, Blink and Zeus, depending on the settlement setup you choose.

### Does Coinsnap hold my Bitcoin?

Coinsnap follows a non-custodial approach and does not hold merchant Bitcoin. Payments are settled to the merchant's configured wallet or payment destination.

### Do I need technical knowledge to install the plugin?

The plugin can be installed through the JTL-Shop plugin manager. Basic experience with installing JTL-Shop plugins is sufficient for the standard setup.

### Where do I find my Store ID and API key?

They are available in your Coinsnap merchant account and are entered in the JTL-Shop Coinsnap payment-method configuration.

### Does the plugin support payment before order completion?

Yes. Support for payment before order completion was added in version 1.0.1.

### Is the plugin available worldwide?

Coinsnap is designed for merchants worldwide. Bitcoin and Lightning payments can be accepted from customers globally, subject to any legal or service restrictions that may apply in the merchant's jurisdiction.

### Where can I get support?

Use the support area in your Coinsnap merchant account or join the official [Coinsnap Telegram channel](https://t.me/coinsnap).

## Changelog

### 1.0.3 — 2025-05-09

- Current plugin version according to `info.xml`.

### 1.0.1

- Added support for payment before order completion.

### 1.0.0

- Initial release.
