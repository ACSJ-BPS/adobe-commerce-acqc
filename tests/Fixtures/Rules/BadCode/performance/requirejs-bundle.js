// Bad: RequireJS bundle configuration — pre-bundling degrades performance with HTTP/2
require.config({
    bundles: {
        'Vendor_Module/js/bundle': [
            'Vendor_Module/js/product-widget',
            'Vendor_Module/js/cart-handler',
            'Vendor_Module/js/checkout-steps'
        ]
    }
});
