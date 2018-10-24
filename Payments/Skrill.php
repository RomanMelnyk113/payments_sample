<?php

namespace App\Libraries\Payments;

use App\Libraries\Interfaces\Payment;
use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Log;
use Omnipay\Skrill\Gateway;
use Config;
use GuzzleHttp\Client;

class Skrill extends Payments implements Payment
{
    const COMPLETE = 2;
    const PENDING = 0;
    const REJECTED = -1;
    const CHARGEBACK = -3;
    const FAILED = -2;

    public $skrill;
    private $config;

    private $provider_name = 'Skrill';

    public $sandbox = false;

    public $statuses = [
        -3 => 'chargeback',
        -2 => 'failed',
        -1 => 'rejected',
        0 => 'pending',
        2, 'complete',
    ];

    public function __construct()
    {
        $this->skrill = new Gateway();

        if (App::environment('local', 'development')) {
            $this->sandbox = true;
        }
        $this->skrill->setTestMode($this->sandbox);

        $this->config = Config::get('skrill');
    }

    public function getProviderName(): string
    {
        return $this->provider_name;
    }

    public function payment(Order $order)
    {
        $this->skrill->setEmail($this->getPaymentEmail($order->currency));
        $this->skrill->setNotifyUrl(route('ipn_route', ['provider' => 'skrill']));

        $data = [
            'status_url' => route('ipn_route', ['provider' => 'skrill']),
            'language' => 'EN',
            'logoUrl' => route('logo'),
            'amount' => round($order->amount, 2),
            'currency' => $order->currency,
            'transactionId' => $order->number,
            'returnUrl' => route('success_page', ['provider' => 'skrill']),
            'cancelUrl' => route('payment_error'),
            'details' => ['item' => "{$order->quantity} {$order->product}"]
        ];

        $response = $this->skrill->purchase($data)->send();

        if ($response->isRedirect()) {
            $response->redirect();
        }

        \Log::useDailyFiles(storage_path() . '/logs/errors.log');
        \Log::error("Skrill payment error: ", [
            'message' => 'Payment was not successful',
            'order_id' => $order->number,
            'response' => $response
        ]);

        return redirect()->route('payment_error');
    }


    public function makeRefund(Order $order)
    {
        $skrill_handle = new SkrillRefund($order->currency);

        $fields = [
            'transaction_id' => $order->transaction_id
        ];

        // Preparation of the refund
        $skrill_handle->prepareRequest($fields);

        \Log::debug("Skrill refund response: ", ['message' => $skrill_handle->getResponse()]);

        if (isset($skrill_handle->error)) {
            \Log::useDailyFiles(storage_path() . '/logs/errors.log');
            \Log::error("Skrill refund error: ", ['message' => $skrill_handle->error]);
            return ['status' => 'error', 'message' => $skrill_handle->error];
        }
        // retrieve session ID and perform the refund
        $skrill_handle->makeRefund($skrill_handle->response['sid']);
        $message = '';
        $status = 'success';
        $code = 200;

        switch ($skrill_handle->response['status']) {
            case self::FAILED:
                $status = 'failed';
                $message = $skrill_handle->getErrorText($skrill_handle->response['error']);
                $code = $skrill_handle->response['error'];
                break;
            case self::PENDING:
                $status = 'pending';
                $message = 'Order refunding is pending, please wait';
                break;
            case self::COMPLETE:
                $message = 'Order has been successful refunded';
                break;
        }
        return ['status' => $status, 'message' => $message, 'code' => $code];
    }

    private function getPaymentEmail(string $currency)
    {

        if ($this->sandbox === true) {
            return $this->config['sandbox_email'];
        }

        if ($currency == 'GBP') {
            return $this->config['gbp_email'];
        } else if ($currency == 'EUR') {
            return $this->config['eur_email'];
        } else {
            return $this->config['email'];
        }
    }
}