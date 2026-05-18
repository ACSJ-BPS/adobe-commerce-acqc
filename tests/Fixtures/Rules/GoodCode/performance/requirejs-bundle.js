// Good: individual module definitions — HTTP/2 serves files in parallel efficiently
define('Vendor_Module/js/product-widget', ['jquery', 'mage/storage'], function ($, storage) {
    'use strict';
    return {
        init: function (config) {
            // module initialization
        }
    };
});
