<?php

namespace App\Libraries\Interfaces;

use App\Models\Order;
use App\Models\Product;

interface Payment
{
    /**
     * Perform checkout action with selected payment gateway with specific order data
     *
     * @param Order $order
     * @param $product
     * @return mixed
     */
    public function payment(Order $order);

    /**
     * Perform refund action for specific order with selected payment gateway
     *
     * @param Order $order
     * @return mixed
     */
    public function makeRefund(Order $order);

    /**
     * Get payment provider name (e.g. Skrill, G2Apay etc)
     *
     * @return string
     */
    public function getProviderName():string;
}