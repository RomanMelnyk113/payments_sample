<?php

namespace App\Libraries\Payments;

use App\Libraries\Interfaces\Payment;

class PaymentsFactory
{
    public static function createPaymentProvider(string $payment_method): Payment
    {
        $payment_method = strtolower($payment_method);
        if (in_array($payment_method, ['btc', 'g2apay', 'bancontact'])) {
            $provider = new G2APay();
        } else {
            $provider = new Skrill();
        }

        return $provider;
    }
}