# Payshen for WooCommerce

Take payments through [Payshen](https://www.payshen.com) in a WooCommerce store. Customers pay on Payshen's secure page with the cards, banks and wallets your payment providers offer, and Payshen routes each payment to the provider most likely to approve it. Payshen is a technology provider: it never holds your money; your providers collect and pay you out.

- Hosted payment page: card details never touch your store.
- Orders update by themselves: signed webhooks, plus a scheduled check for 48 hours so a lost message never leaves an order wrong.
- Refunds from the order screen, full or partial.
- Manual capture: authorize at checkout, capture when you ship; cancelling the order releases the money.
- Cart and Checkout blocks and the classic checkout; High-Performance Order Storage.
- Test mode against the Payshen sandbox.

Requires WordPress 6.5+, WooCommerce 8.5+ and PHP 8.2+ (with PHP's October 2026 security release: 8.2.35, 8.3.36, 8.4.27, 8.5.12 or newer). Tested with WordPress 7.1 and WooCommerce 11.2.

## Install

Download `payshen-for-woocommerce.zip` from the [latest release](../../releases/latest), then in WordPress: Plugins → Add New → Upload Plugin.

## Set up

1. WooCommerce → Settings → Payments → Payshen: click **Connect with Payshen**, sign in, pick the workspace and approve. The store gets its own test key and webhook; nothing to copy.
2. Enable it, keep Test mode on, choose automatic or manual capture and the kinds of payment to offer. Place a test order.
3. For live payments, click **Connect again**, tick live payments and approve, then turn Test mode off.

Keys can also be entered by hand: a test API key (`ps_test_…`) from Payshen → Developers, and the signing secret (`whsec_…`) of a webhook endpoint for the URL shown in the plugin settings.

How Connect with Payshen works: the plugin sends the admin to Payshen with a random `state` and a PKCE (S256) challenge. After approval, Payshen sends the admin back with a one-time code, which the store's server trades, with the PKCE verifier that never left the site, at `POST /api/connect/token`. The keys can only create, read, capture, release and refund payments. Disconnect calls `POST /api/connect/disconnect`, which revokes the key and removes the store's webhook endpoint.

Full guide: https://www.payshen.com/developers

## Develop

```sh
composer install
php tests/lint.php
vendor/bin/phpunit
vendor/bin/phpcs
```

`tests/integration/smoke.php` runs inside a WordPress site with WooCommerce (`wp eval-file`) against a mocked Payshen API.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
