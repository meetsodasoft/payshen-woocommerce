=== Payshen for WooCommerce ===
Contributors: ratomirjovanovic
Tags: payments, payment gateway, woocommerce, checkout, orchestration
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.5
WC tested up to: 11.2
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take payments through Payshen. Customers pay on Payshen's secure page with the cards, banks and wallets your payment providers offer.

== Description ==

Payshen connects your store to the payment providers you already use, and routes each payment to the one most likely to approve it. Payshen is a technology provider: it never holds your money. Your providers collect the payments and pay you out, as they do today.

With this plugin:

* Customers choose Payshen at checkout and pay on Payshen's secure payment page. Card details never touch your store.
* You choose which kinds of payment to offer: card, bank, wallet and crypto. The page shows only what your connected providers can take.
* Orders update by themselves. Payshen tells your store the moment a payment is decided (webhooks), and the store also checks with Payshen on a schedule for 48 hours, so a missed message never leaves an order wrong.
* Refund from the order screen, in full or in part.
* Manual capture: authorize at checkout and capture when you ship. Cancelling the order releases the money.
* Works with the Cart and Checkout blocks and the classic checkout, and with High-Performance Order Storage.
* Test mode with the Payshen sandbox: no money moves.

= External service =

This plugin sends payment requests to the Payshen API (https://www.payshen.com) to create, read, capture, release and refund payments. It sends the order amount, currency, order number, the customer's billing name, email, phone, address and IP address (which some payment providers require), and the addresses of your order pages. Nothing is sent until a customer chooses Payshen at checkout or you act on a Payshen order. The plugin makes no other external calls and does no tracking.

* Payshen: https://www.payshen.com
* Terms: https://www.payshen.com/terms
* Privacy policy: https://www.payshen.com/privacy

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Go to WooCommerce > Settings > Payments > Payshen.
3. Paste your test API key (ps_test_...) from Payshen > Developers.
4. In Payshen > Developers, add a webhook endpoint for Sandbox with the Webhook URL shown in the settings, subscribe it to all payment events, and paste its signing secret (whsec_...) into "Test webhook signing secret".
5. Place a test order. When everything works, add your live API key and a Production webhook endpoint with its signing secret, then turn off Test mode.

== Frequently Asked Questions ==

= Does Payshen hold my money? =

No. Payshen is a technology provider. Your payment providers collect the money and pay it out to you.

= Which payment methods can customers use? =

The ones your connected providers can take, among those you allow in the settings (card, bank, wallet, crypto). The customer picks one on Payshen's page.

= What happens if a webhook does not arrive? =

The store checks the payment with Payshen 5 minutes, 30 minutes, 2 hours, 12 hours and 48 hours after checkout, until it has a final answer. You can also use the order action "Check payment status (Payshen)" at any time.

= What does the customer see if their payment is still being confirmed? =

The order received page says the payment is being confirmed. The order moves to Processing, and the customer gets the usual email, as soon as Payshen confirms the payment.

= How does manual capture work? =

Set Capture to Manual. Paid orders wait On hold with the payment authorized. Set the order to Processing or Completed, or use the order action "Capture payment (Payshen)", to collect the money. If you lower the order total first, only the new total is captured. Cancelling the order releases the authorization.

= Can I use the Payshen staging environment? =

Add `define( 'PAYSHEN_API_BASE_URL', 'https://staging.payshen.com' );` to wp-config.php. The settings page says when a non-production API is in use.

= Where are the logs? =

WooCommerce > Status > Logs, source "payshen". Errors are always logged. Turn on Debug log for more. Keys, secrets and customer details are never logged.

== Changelog ==

= 1.0.2 =
* Security: a test-mode payment never marks an order paid once the store takes live payments.
* Repeated visits to the thank-you page no longer queue payment-service reads.
* A payment page address is checked before the customer is sent back to it.
* Uninstall removes the settings from every site of a multisite network.

= 1.0.1 =
* Ready for the WordPress.org plugin directory: contributor and version headers. No change in behaviour.

= 1.0.0 =
* First release.
