var config = {
    map: {
        '*': {
            'Magento_Ui/js/lib/knockout/bindings/outer_click': 'Magento_Ui/js/lib/knockout/bindings/outer_click',
            'customSlider': 'Vendor_Theme/js/custom-slider',
            'loyaltyWidget': 'Vendor_Theme/js/loyalty-widget'
        }
    },
    paths: {
        'Vendor_Theme/js/vendor': 'Vendor_Theme/js/vendor/bundle'
    },
    shim: {
        'Vendor_Theme/js/vendor/bundle': {
            deps: ['jquery']
        }
    }
};
