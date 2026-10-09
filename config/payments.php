<?php

return [
    /*
    | Default payment gateway driver. Registered drivers live in
    | App\Services\Payments\PaymentGatewayManager.
    */
    'default_driver' => env('PAYMENT_DEFAULT_DRIVER', 'paystack'),

    'currency' => env('PAYMENT_DEFAULT_CURRENCY', 'GHS'),
];
