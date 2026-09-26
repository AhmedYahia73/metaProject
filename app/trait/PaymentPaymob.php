<?php

namespace App\trait;

use Illuminate\Support\Facades\Http;

trait PaymentPaymob
{
    // This Trait About Srvic Payment Paymob

    use placeOrder;

    public function getToken($payment_method_auto)
    {
        // this function takes api key from env.file and get token from paymob accept
        $response = Http::post('https://accept.paymob.com/api/auth/tokens', [
            'api_key' => $payment_method_auto->api_key,
        ]);

        return $response->object()->token;
    }

    public function createOrder($request, $tokens, $user)
    {
        // This function takes last step token and send new order to paymob dashboard
        // in This Function We Make Order For Returned Token
        // $amount = new Checkoutshow; here you add your checkout controller
        // $total = $amount->totalProductAmount(); total amount function from checkout controller
        // here we add example for test only
        $items = $this->placeOrder($request, $user);

        if (isset($items['errors']) && ! empty($items['errors'])) {
            return $items;
        }
        $totalAmountCents = (int) round($items['payment']->amount);

        //  $total = 100;
        // $items = [
        //     [ "name"=> "ASC1515",
        //         "amount_cents"=> "500000",
        //         "description"=> "Smart Watch",
        //         "quantity"=> "1"
        //     ],
        //     [
        //         "name"=> "ERT6565",
        //         "amount_cents"=> "200000",
        //         "description"=> "Power Bank",
        //         "quantity"=> "1"
        //     ]
        // ];
        // $data = $items;

        $data = [
            'auth_token' => $tokens,
            'delivery_needed' => 'false',
            'amount_cents' => $totalAmountCents,
            'currency' => 'EGP',
            'items' => $items['items'],
        ];
        $response = Http::post('https://accept.paymob.com/api/ecommerce/orders', $data);

        // Update Transaction order For Payment
        $payment = $items['payment']->id;
        $order_id = $response['id'];
        $payment = $this->generateUniqueTransactionId($payment, $order_id);
        // Update Transaction order For Payment

        return $response->object();
    }

    public function getPaymentToken($user, $total_amount, $order, $token, $payment_method_auto)
    {
        // this function to add details to paymob order dashboard and you can fill this data from your Model Class as below

        // $amountt = new Checkoutshow;
        // $totall = $amountt->totalProductAmount();
        // $todayDate = Carbon::now();
        // $dataa = Order::where('user_id',Auth::user()->id)->whereDate('created_at',$todayDate)->orderBy('created_at','desc')->first();

        // we just added dummy data for test
        // all data we fill is required for paymob
        $billingData = [
            'apartment' => '45', // example $dataa->appartment
            'email' => $user->email ?? 'client@gmail.com', // example $dataa->email
            'floor' => '7',
            'first_name' => $user->f_name ?? 'client',
            'street' => 'NA',
            'building' => 'NA',
            'phone_number' => $user->phone ?? '123',
            'shipping_method' => 'NA',
            'postal_code' => 'NA',
            'city' => 'Alexandria',
            'country' => 'Egypt',
            'last_name' => $user->l_name ?? '2',
            'state' => '0',
        ];

        $data = [
            'auth_token' => $token,
            'amount_cents' => $total_amount,
            'expiration' => 3600,
            'order_id' => $order->id, // this order id created by paymob
            'billing_data' => $billingData,
            'currency' => 'EGP',
            'integration_id' => $payment_method_auto->integration_id,
        ];
        $response = Http::post('https://accept.paymob.com/api/acceptance/payment_keys', $data);

        return $response->object()->token;
    }

    private function generateUniqueTransactionId($payment_id, $order_id)
    {
        $payment = $this->order->find($payment_id);
        $updatePayment = $payment->update(['transaction_id' => $order_id]);

        return $payment;
    }
}
