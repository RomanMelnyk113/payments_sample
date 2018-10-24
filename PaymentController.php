<?php

namespace App\Http\Controllers;

use App\Libraries\Converter;
use App\Libraries\Maxmind;
use App\Models\Black_list;
use App\Models\DiscountCode;
use App\Models\DiscountToOrder;
use App\Models\Order;
use App\Models\Product;
use Carbon\Carbon;
use Event;
use Illuminate\Http\Request;

use Log;
use Auth;
use App\Libraries\Payments\PaymentsFactory;
use Session;
use Validator;

class PaymentController extends Controller
{
    /**
     * Handle checkout request, process all data, create new order and then redirect customer to provider
     * @param Request $request
     * @param Converter $converter
     * @param Maxmind $maxmind
     * @return mixed
     */
    public function pay(Request $request, Converter $converter, Maxmind $maxmind)
    {
        Log::useDailyFiles(storage_path() . '/logs/payments.log');
        Log::info('Order pay parameters: ', ['params' => $request->all(), 'session' => Session::get('order_payment_info')]);

        $data = [];

        // NOTE: keys in $data should be the same as Order columns titles !!!!
        $product_id = Session::get('order_payment_info.product_id');
        $data['amount'] = Session::get('order_payment_info.order_price');
        $data['price'] = Session::get('order_payment_info.product_price');
        $data['quantity'] = $initial_quantity = Session::get('order_payment_info.quantity');
        $data['currency'] = Session::get('order_payment_info.currency');
        $data['nick'] = Session::get('order_payment_info.RSN');

        // users registered via social networks might not have emails (FACEBOOK issue)
        if (Auth::check()) {
            $data['buyer_email'] = Auth::user()->email;
        } elseif (!empty($request->input('user_email'))) {
            $data['buyer_email'] = $request->input('user_email');
        } elseif (!empty(Session::get('order_payment_info.user_email'))) {
            $data['buyer_email'] = Session::get('order_payment_info.user_email');
        }
        // this is needed for users who is tried to do purchase without user_email
        $data['payment'] = $request->get('PaymentType', Session::get('order_payment_info.PaymentType', 'G2APay'));
        if (!Session::has('order_payment_info.PaymentType') && !empty($data['payment'])) {
            Session::put('order_payment_info.PaymentType', $data['payment']);
        }
        $discount_code = $request->get('discount_code_hid', Session::get('order_payment_info.discount_code_hid'));
        if (!Session::has('order_payment_info.discount_code_hid') && !empty($discount_code)) {
            Session::put('order_payment_info.discount_code_hid', $discount_code);
        }
        Session::save();

        $user = Auth::user();

        // redirect guests to the login page if they don't provide us emails
        if (!isset($data['buyer_email']) && !Auth::check()) {
            return redirect()->route('sign_in')->withInput()->with('show_email_input', 1);
        }

        $data['ip'] = request()->ip();
        $geolocation = $maxmind->detectGeolocation($data['ip']);
        $data['city'] = $geolocation['city'];
        $data['country'] = $geolocation['country'];

        $product = Product::where('published', 1)->where('id', $product_id)->first();

        // Paysafecard fee
        if ($data['payment'] == 'PSC') {
            $data['quantity'] -= $initial_quantity * 0.1;
        }

        # add discount value
        if (!empty($discount_code)) {
            $discount = DiscountCode::withTrashed()->where('code', $discount_code)->first();
            if ($discount) {
                if ($discount->type == 'gold')
                    $bonus_quantity = floatval($discount->amount);
                else
                    $bonus_quantity = round($initial_quantity * $discount->amount / 100, 2);

                // add bonus gold from discount
                $data['quantity'] += $bonus_quantity;
            }
        }


        $provider = PaymentsFactory::createPaymentProvider($data['payment']);
        $data['payment_provider'] = $provider->getProviderName();


        $data['number'] = make_order_number();
        $data['fee'] = $data['profit'] = 0;
        $data['product'] = $product->title;
        $data['buyer_id'] = Auth::id();
        $data['buyer_name'] = Auth::check() ? $user->name : $data['buyer_email'];
        $data['usd_amount'] = $data['currency'] != 'USD' ? $converter->convertToUsd($data['currency'], $data['amount']) : $data['amount'];
        $data['status'] = Order::CREATED;
        $data['payment_status'] = Payments::CREATED;
        $data['risk'] = $data['ip_user_type'] = $data['ip_postal_code'] = '';

        try {
            $order = Order::createNewOrder($data);
        } catch (\Exception $exception) {
            Log::useDailyFiles(storage_path() . '/logs/payments.log');
            Log::error('Payment error: ', ['message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()]);
            return redirect()->route('error_page');
        }

        if ($user) {
            if ($user->status == 'banned') {
                $blacklist = new Black_list();
                $blacklist->addOrder($order);
            }
        }

        if (!empty($discount_code) && isset($discount)) {
            // save info about used discount to db
            DiscountToOrder::create([
                'discount_id' => $discount->id,
                'order_id' => $order->id
            ]);
        }

        // Perform a payment via selected provider
        return $provider->payment($order);

    }

    public function makeRefund(Request $request)
    {
        $order_id = $request->get('order_id');

        if ($order_id) {
            $order = Order::where('id', $order_id)->first();

            $order->manager_id = Auth::user()->id;
            $provider = PaymentsFactory::createPaymentProvider($order->payment_provider);

            try {
                $res = $provider->makeRefund($order);
                \Log::notice("Refund info: ", ['result' => $res]);
            } catch (Exception $exception) {
                \Log::error("Refund error: ", ['error' => $exception->getMessage(),
                    'provider' => $order->payment_provider]);
            }

            if ($res['status'] == 'success') {
                $order->status = 'Refunded';
                $order->save();

                $status = new OrderStatus();
                $status->status = 'Refunded';
                $status->order_id = $order->id;
                $status->save();
                \Event::fire(new OrderRefunded($order));
                return response()->json(['code' => 200, 'message' => 'Refunded', 'user_id' => $order->buyer_id]);
            } elseif ($res['status'] == 'failed') {
                return response()->json(['code' => 500, 'message' => $res['message'], 'error_code' => $res['code']]);
            } elseif (in_array($res['status'], ['pending', 'error'])) {
                return response()->json(['code' => 500, 'message' => $res['message']]);
            }
        }
        return response()->json(['code' => 500, 'message' => 'Something went wrong']);
    }
}



