# Payshen for WooCommerce

Take payments through [Payshen](https://www.payshen.com) in a WooCommerce store. Customers pay on Payshen's secure page with the cards, banks and wallets your payment providers offer, and Payshen routes each payment to the provider most likely to approve it. Payshen is a technology provider: it never holds your money; your providers collect and pay you out.

- Hosted payment page: card details never touch your store.
- Orders update by themselves: signed webhooks, plus a scheduled check for 48 hours so a lost message never leaves an order wrong.
- Refunds from the order screen, full or partial.
- Manual capture: authorize at checkout, capture when you ship; cancelling the order releases the money.
- Cart and Checkout blocks and the classic checkout; High-Performance Order Storage.
- Test mode against the Payshen sandbox.

Requires WordPress 6.5+, WooCommerce 8.5+ and PHP 8.1+. Tested with WordPress 7.1 and WooCommerce 11.2.

## Install

Download `payshen-for-woocommerce.zip` from the [latest release](../../releases/latest), then in WordPress: Plugins → Add New → Upload Plugin.

## Set up

1. WooCommerce → Settings → Payments → Payshen: enable it, keep Test mode on, paste your test API key (`ps_test_…`).
2. In Payshen → Developers → Webhooks, add the webhook URL shown in the plugin settings (sandbox), subscribe it to payment events, and paste its signing secret (`whsec_…`) into the plugin.
3. Choose automatic or manual capture and the kinds of payment to offer. Place a test order.
4. For live payments, add your live key and live webhook secret and turn Test mode off.

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
