<?php
/**
 * Unit tests for the parts that need no WordPress.
 *
 * @package Payshen_For_WooCommerce
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

$includes = dirname( __DIR__ ) . '/includes/';
require $includes . 'class-payshen-amount.php';
require $includes . 'class-payshen-signature.php';
require $includes . 'class-payshen-status.php';
require $includes . 'class-payshen-keys.php';
require $includes . 'class-payshen-customer.php';
require $includes . 'class-payshen-api-exception.php';
require $includes . 'class-payshen-api-client.php';
