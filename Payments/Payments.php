<?php

namespace App\Libraries\Payments;


class Payments
{
    const CREATED = 'created';
    const DELIVERED = 'complete';
    const PENDING = 'pending';
    const REFUNDED = 'refunded';
    const REJECTED = 'rejected';
    const NEW = 'new';
    const DISPUTE = 'dispute';
    const CHARGEBACK = 'chargeback';
    const REVERSED = 'reversed';
    const CANCELED_REVERSAL = 'canceled_reversal';
    const COMPLAINT = 'complaint';
    const FAILED = 'failed';
    const UNKNOWN = 'unknown';
    const BLOCKED = 'blocked';

    public function calculateProfit(float $payment_sum, float $fee, float $product_sell_price,
                                     float $quantity, string $currency = 'USD', int $product_id = null): float
    {
        # additional fee
        if ($product_id === 1) {
            $product_sell_price += 0.02;
        } else if ($product_id === 2) {
            $product_sell_price += 0.01;
        }

        # convert all to USD
        if ($currency != 'USD') {
            $converter = new Converter();
            $payment_sum = $converter->convertToUsd($currency, $payment_sum);
            $fee = $converter->convertToUsd($currency, $fee);
        }

        // deduct 3% from total received money amount to cover currency exchange costs.
        if (!in_array($currency, ['USD', 'GBP', 'EUR'])) {
            $payment_sum -= $payment_sum * 0.03;
        }
        return $payment_sum - $fee - ($quantity * $product_sell_price);
    }
}