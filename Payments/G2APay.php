<?php

namespace App\Libraries\Payments;

use App\Libraries\Interfaces\Payment;
use App\Models\Order;
use App\Models\Product;
use Config;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;

class G2APay extends Payments implements Payment
{
    private $token_url = 'https://checkout.pay.g2a.com/index/createQuote';
    private $sandbox_token_url = 'https://checkout.test.pay.g2a.com/index/createQuote';
    private $redirect = 'https://checkout.pay.g2a.com/index/gateway?token=';
    private $sandbox_redirect = 'https://checkout.test.pay.g2a.com/index/gateway?token=';
    private $rest_url = 'https://pay.g2a.com/rest/transactions/';
    private $sandbox_rest_url = 'https://www.test.pay.g2a.com/rest/transactions/';

    private $api_hash;
    private $sandbox_api_hash;

    private $secret;
    private $sandbox_secret;

    private $merchant_email;

    private $sandbox = false;

    private $provider_name = 'G2APay';

    public function __construct()
    {
        if (App::environment('local', 'development')) {
            $this->sandbox = true;
        }
        $this->api_hash = Config::get('g2apay.api_hash');
        $this->sandbox_api_hash = Config::get('g2apay.sandbox_api_hash');
        $this->secret = Config::get('g2apay.secret');
        $this->sandbox_secret = Config::get('g2apay.sandbox_secret');
        $this->merchant_email = $this->sandbox ? Config::get('g2apay.sandbox_merchant_email') : Config::get('g2apay.merchant_email');
    }

    public function getProviderName(): string
    {
        return $this->provider_name;
    }

    public function payment(Order $order)
    {
        $product_url = url($order->product->url);

        $url_failure = route('payment_error');
        $url_ok = route('success_page', ['provider' => 'g2apay']);

        $params = array(
            'api_hash' => $this->getApiHash(),
            'hash' => $this->g2apayHash($order->amount, $order->currency, $order->number),
            'order_id' => $order->number,
            'amount' => $order->amount,
            'currency' => $order->currency,
            'url_failure' => $url_failure,
            'url_ok' => $url_ok,
            'items' => [
                [
                    'id' => $order->product->id,
                    'sku' => $order->product->id,
                    'name' => $order->product->title,
                    'amount' => $order->amount,
                    // this hack is used to allow perform purchases with float quantity
                    'price' => $order->amount, // $order->price,
                    'qty' => 1, // $order->quantity
                    'type' => 'product',
                    'url' => $product_url,
                    'extra' => $order->quantity,
                ]
            ]
        );

        $guzzle = new Client();
        try {
            $result = $guzzle->post($this->getTokenUrl(), [
                'form_params' => $params
            ]);
        } catch (\Exception $exception) {
            \Log::useDailyFiles(storage_path() . '/logs/errors.log');
            \Log::error('G2Apay payment error: ', ['message' => $exception->getMessage()]);
            return redirect()->route('payment_error');
        }

        if (isset($result) && $result->getStatusCode() == 200) {
            $response = $result->getBody()->getContents();
            if ($response) {
                $result = $this->g2apayDecode($response);
                $token = isset($result['token']) ? $result['token'] : 0;
                return redirect($this->getRedirectUrl() . $token);
            }
        }
        return redirect()->route('payment_error');

    }

    public function makeRefund(Order $order)
    {
        $params = [
            'action' => 'refund',
            'amount' => $order->amount,
            'hash' => $this->getRefundHash($order->sale_id, $order->number, $order->amount, $order->amount),
        ];

        $guzzle = new Client(['verify' => false, 'http_errors' => false, 'timeout' => 5]);

        $result = $guzzle->put($this->getRestUrl() . $order->sale_id, [
            'headers' => [
                'Authorization' => $this->getApiHash() . ';' . $this->getAuthorizationHash(),
            ],
            'form_params' => $params
        ]);
        $content = $this->g2apayDecode($result->getBody()->getContents());
        $code = $result->getStatusCode();

        if ($code == 200) {
            return ['status' => 'success', 'message' => $content, 'code' => $code];
        }

        return ['status' => 'failed', 'message' => $content, 'code' => $code];

    }

    private function getTokenUrl()
    {
        return $this->sandbox ? $this->sandbox_token_url : $this->token_url;
    }

    private function getRedirectUrl()
    {
        return $this->sandbox ? $this->sandbox_redirect : $this->redirect;
    }

    private function getRestUrl()
    {
        return $this->sandbox ? $this->sandbox_rest_url : $this->rest_url;
    }

    private function getApiHash()
    {
        return $this->sandbox ? $this->sandbox_api_hash : $this->api_hash;
    }

    private function getSecret()
    {
        return $this->sandbox ? $this->sandbox_secret : $this->secret;
    }

    private function g2apayHash($amount, $currency, $order_id)
    {
        return hash('sha256', $order_id . $this->g2apayRound($amount) . $currency . $this->getSecret());
    }

    private function getAuthorizationHash()
    {
        $string = $this->getApiHash() . $this->merchant_email . $this->getSecret();
        return hash('sha256', $string);
    }

    private function getRefundHash($transactionId, $userOrderId, $amount, $refundAmount)
    {
        $string = $transactionId . $userOrderId . $amount . $refundAmount . $this->getSecret();
        return hash('sha256', $string);
    }

    private function g2apayDecode($data)
    {
        return json_decode($data, 1);
    }

    private function g2apayRound($amount)
    {
        return round($amount, 2);
    }
}