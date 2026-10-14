/**
 * Payshen in the WooCommerce Checkout block.
 *
 * Plain script, no build step: it uses the globals WooCommerce and WordPress
 * load (wc.wcBlocksRegistry, wc.wcSettings, wp.element, wp.htmlEntities, wp.i18n).
 */
( function () {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var wcSettings = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	if ( ! registry || ! wcSettings || ! element ) {
		return;
	}

	var createElement = element.createElement;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( s ) {
		return s;
	};
	var __ = window.wp.i18n ? window.wp.i18n.__ : function ( s ) {
		return s;
	};

	var settings = wcSettings.getSetting( 'payshen_data', {} ) || {};
	var title = decode( settings.title || '' ) || __( 'Pay online', 'payshen-for-woocommerce' );
	var description = decode( settings.description || '' );

	function Content() {
		return description ? createElement( 'div', { className: 'payshen-description' }, description ) : null;
	}

	function Label( props ) {
		var PaymentMethodLabel = props && props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? createElement( PaymentMethodLabel, { text: title } ) : createElement( 'span', null, title );
	}

	registry.registerPaymentMethod( {
		name: 'payshen',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: Array.isArray( settings.supports ) ? settings.supports : [ 'products' ],
		},
	} );
} )();
