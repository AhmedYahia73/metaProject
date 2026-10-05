<?php

namespace App\trait;

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\Package;
use App\Models\Paymob as PaymobModel;
use App\Models\WhatsItem;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

trait paymob
{
    /**
     * Calculate base price, discount, tax, and final price for a package.
     *
     * @return array{price: float, total_discount: float, total_tax: float, final_price: float}
     */
    public function calculatePackagePricing(Package $package): array
    {
        $package->loadMissing(['discount', 'tax']);

        $basePrice = (float) $package->price;
        $totalDiscount = 0.0;
        $totalTax = 0.0;

        $discount = $package->discount;
        if ($discount) {
            $discountAmount = (float) ($discount->amount ?? $discount->value ?? 0);

            $isWithinPeriod = true;
            $today = Carbon::today();

            if (! empty($discount->from)) {
                $fromDate = Carbon::parse($discount->from)->startOfDay();
                if ($today->lt($fromDate)) {
                    $isWithinPeriod = false;
                }
            }

            if (! empty($discount->to)) {
                $toDate = Carbon::parse($discount->to)->endOfDay();
                if ($today->gt($toDate)) {
                    $isWithinPeriod = false;
                }
            }

            if ($isWithinPeriod && $discountAmount > 0) {
                $isPercentage = in_array(strtolower((string) $discount->type), ['percentage', 'percent', '%'], true);
                $totalDiscount = $isPercentage
                    ? ($basePrice * $discountAmount) / 100
                    : $discountAmount;

                $totalDiscount = min($totalDiscount, $basePrice);
            }
        }

        $priceAfterDiscount = max(0.0, $basePrice - $totalDiscount);

        $tax = $package->tax;
        if ($tax) {
            $taxAmount = (float) ($tax->amount ?? $tax->value ?? 0);
            if ($taxAmount > 0) {
                $isTaxPercentage = in_array(strtolower((string) $tax->type), ['percentage', 'percent', '%'], true);
                $totalTax = $isTaxPercentage
                    ? ($priceAfterDiscount * $taxAmount) / 100
                    : $taxAmount;
            }
        }

        $finalPrice = max(0.0, $basePrice - $totalDiscount + $totalTax);

        return [
            'price' => round($basePrice, 2),
            'total_discount' => round($totalDiscount, 2),
            'total_tax' => round($totalTax, 2),
            'final_price' => round($finalPrice, 2),
        ];
    }

    /**
     * Get the Paymob payment iframe link for a given order.
     *
     * Credentials are read dynamically from the Paymob model.
     *
     * @throws HttpResponseException
     */
    public function getPaymobPaymentLink(Order $order): string
    {
        $paymob = PaymobModel::first();

        if (! $paymob || empty($paymob->api_key) || empty($paymob->integration_id) || empty($paymob->iframe_id)) {
            Log::error('Paymob integration settings are incomplete or missing.', [
                'has_paymob' => ! empty($paymob),
                'order_id' => $order->id,
            ]);

            throw new HttpResponseException(response()->json([
                'status' => false,
                'message' => 'Paymob payment gateway is not configured. Please contact administrator.',
            ], Response::HTTP_SERVICE_UNAVAILABLE));
        }

        // 1. Authentication Token
        $authResponse = Http::timeout(30)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://accept.paymob.com/api/auth/tokens', [
                'api_key' => $paymob->api_key,
            ]);

        if (! $authResponse->successful() || ! $authResponse->json('token')) {
            Log::error('Paymob Auth Token failed', [
                'response' => $authResponse->json() ?? $authResponse->body(),
                'status' => $authResponse->status(),
                'order_id' => $order->id,
            ]);

            throw new HttpResponseException(response()->json([
                'status' => false,
                'message' => 'Failed to authenticate with Paymob payment gateway.',
                'error' => $authResponse->json('message') ?? 'Paymob auth failed.',
            ], Response::HTTP_BAD_GATEWAY));
        }

        $authToken = $authResponse->json('token');
        $amountCents = (int) round(((float) $order->final_price) * 100);

        // 2. Order Registration
        $orderPayload = [
            'auth_token' => $authToken,
            'delivery_needed' => 'false',
            'amount_cents' => $amountCents,
            'currency' => 'EGP',
            'merchant_order_id' => (string) $order->id,
            'items' => [],
        ];

        $orderResponse = Http::timeout(30)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://accept.paymob.com/api/ecommerce/orders', $orderPayload);

        // Retry if merchant_order_id is duplicate
        if (! $orderResponse->successful() || ! $orderResponse->json('id')) {
            if ($orderResponse->status() === 422 || str_contains($orderResponse->body(), 'duplicate')) {
                unset($orderPayload['merchant_order_id']);
                $orderResponse = Http::timeout(30)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://accept.paymob.com/api/ecommerce/orders', $orderPayload);
            }
        }

        if (! $orderResponse->successful() || ! $orderResponse->json('id')) {
            Log::error('Paymob Order Registration failed', [
                'response' => $orderResponse->json() ?? $orderResponse->body(),
                'status' => $orderResponse->status(),
                'order_id' => $order->id,
            ]);

            throw new HttpResponseException(response()->json([
                'status' => false,
                'message' => 'Failed to create order on Paymob.',
                'error' => $orderResponse->json('message') ?? 'Paymob order registration failed.',
            ], Response::HTTP_BAD_GATEWAY));
        }

        $paymobOrderId = $orderResponse->json('id');

        // Store Paymob order ID in transaction_id for callback identification
        $order->update([
            'transaction_id' => (string) $paymobOrderId,
        ]);

        // 3. Payment Key Request
        $user = $order->user;
        $nameParts = explode(' ', trim($user?->name ?? 'User'), 2);
        $firstName = ! empty($nameParts[0]) ? $nameParts[0] : 'User';
        $lastName = ! empty($nameParts[1]) ? $nameParts[1] : 'Customer';
        $email = $user?->email ?: 'user_'.$order->user_id.'@metaproject.com';
        $phone = $user?->phone ?: '01000000000';

        $paymentKeyPayload = [
            'auth_token' => $authToken,
            'amount_cents' => $amountCents,
            'expiration' => 3600,
            'order_id' => $paymobOrderId,
            'billing_data' => [
                'apartment' => 'NA',
                'email' => $email,
                'floor' => 'NA',
                'first_name' => $firstName,
                'street' => 'NA',
                'building' => 'NA',
                'phone_number' => $phone,
                'shipping_method' => 'NA',
                'postal_code' => 'NA',
                'city' => 'Cairo',
                'country' => 'EG',
                'last_name' => $lastName,
                'state' => 'Cairo',
            ],
            'currency' => 'EGP',
            'integration_id' => (int) $paymob->integration_id,
            'lock_order_when_paid' => 'false',
        ];

        $paymentKeyResponse = Http::timeout(30)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://accept.paymob.com/api/acceptance/payment_keys', $paymentKeyPayload);

        if (! $paymentKeyResponse->successful() || ! $paymentKeyResponse->json('token')) {
            Log::error('Paymob Payment Key failed', [
                'response' => $paymentKeyResponse->json() ?? $paymentKeyResponse->body(),
                'status' => $paymentKeyResponse->status(),
                'order_id' => $order->id,
            ]);

            throw new HttpResponseException(response()->json([
                'status' => false,
                'message' => 'Failed to obtain Paymob payment key.',
                'error' => $paymentKeyResponse->json('message') ?? 'Paymob payment key request failed.',
            ], Response::HTTP_BAD_GATEWAY));
        }

        $paymentToken = $paymentKeyResponse->json('token');
        $iframeId = $paymob->iframe_id;

        return "https://accept.paymob.com/api/acceptance/iframes/{$iframeId}?payment_token={$paymentToken}";
    }

    /**
     * Handle Paymob webhook or redirection callback.
     */
    public function paymobCallback(Request $request): JsonResponse
    {
        $paymob = PaymobModel::first();

        $paymobTransactionId = $request->input('id')
            ?? $request->input('obj.id')
            ?? $request->input('transaction_id');

        $paymobOrderId = $request->input('order')
            ?? $request->input('obj.order.id');

        $merchantOrderId = $request->input('merchant_order_id')
            ?? $request->input('obj.order.merchant_order_id')
            ?? $request->input('order_id');

        $successRaw = $request->input('success')
            ?? $request->input('obj.success');

        $isSuccess = filter_var($successRaw, FILTER_VALIDATE_BOOLEAN);

        Log::info('Paymob callback received', [
            'transaction_id' => $paymobTransactionId,
            'order_id' => $paymobOrderId,
            'merchant_order_id' => $merchantOrderId,
            'success' => $isSuccess,
        ]);

        // Find the Order
        $order = null;

        if ($paymobOrderId) {
            $order = Order::where('transaction_id', (string) $paymobOrderId)->first();
        }

        if (! $order && $paymobTransactionId) {
            $order = Order::where('transaction_id', (string) $paymobTransactionId)->first();
        }

        if (! $order && $merchantOrderId) {
            $order = Order::find($merchantOrderId);
        }

        if (! $order) {
            Log::warning('Paymob callback: Order not found', [
                'transaction_id' => $paymobTransactionId,
                'paymob_order_id' => $paymobOrderId,
                'merchant_order_id' => $merchantOrderId,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Order not found for transaction.',
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify HMAC if present and secret is set
        $hmacSecret = $paymob?->Hmac;
        $receivedHmac = $request->input('hmac') ?? $request->input('obj.hmac');

        if ($hmacSecret && $receivedHmac) {
            if (! $this->verifyPaymobHmac($request, $hmacSecret)) {
                Log::warning('Paymob callback: Invalid HMAC signature', [
                    'order_id' => $order->id,
                    'transaction_id' => $paymobTransactionId,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Invalid HMAC signature.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Final transaction identifier to persist
        $finalTransactionId = (string) ($paymobTransactionId ?: $paymobOrderId ?: $order->transaction_id);

        if ($isSuccess) {
            $order = $this->approvePaymobOrder($order, $finalTransactionId);

            return response()->json([
                'status' => true,
                'message' => 'Payment approved and subscription activated successfully.',
                'data' => [
                    'order_id' => $order->id,
                    'transaction_id' => $order->transaction_id,
                    'status' => 'approved',
                    'channel' => $order->channel,
                ],
            ]);
        }

        // Payment failed or was rejected
        $order->update([
            'status' => 'faild',
            'transaction_id' => $finalTransactionId,
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Payment failed or was declined.',
            'data' => [
                'order_id' => $order->id,
                'transaction_id' => $order->transaction_id,
                'status' => 'faild',
            ],
        ], Response::HTTP_PAYMENT_REQUIRED);
    }

    /**
     * Approve and activate an order upon successful Paymob payment.
     */
    public function approvePaymobOrder(Order $order, ?string $transactionId = null): Order
    {
        $package = $order->package;
        $fromDate = Carbon::now();
        $months = max(1, (int) ($package?->months ?? 1));
        $toDate = $fromDate->copy()->addMonths($months);

        $updateData = [
            'status' => 'approved',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
        ];

        if ($transactionId) {
            $updateData['transaction_id'] = (string) $transactionId;
        }

        $order->update($updateData);

        $msgsToAdd = $order->msgs ?: (int) ($package?->msg_number ?? 0);

        if ($order->isWhatsApp()) {
            /** @var WhatsItem|null $whatsItem */
            $whatsItem = $order->whatsItem;
            if ($whatsItem) {
                $whatsItem->update([
                    'phone_status' => 'active',
                    'start_date' => $fromDate->toDateString(),
                    'end_date' => $toDate->toDateString(),
                    'msg_number' => ((int) $whatsItem->msg_number) + $msgsToAdd,
                ]);
            }
        } elseif ($order->isMessenger()) {
            /** @var MessengerAccount|null $account */
            $account = $order->messengerAccount;
            if ($account) {
                $account->update([
                    'status' => 'active',
                    'start_date' => $fromDate->toDateString(),
                    'end_date' => $toDate->toDateString(),
                    'msg_number' => ((int) $account->msg_number) + $msgsToAdd,
                ]);

                // Subscribe Facebook page to webhook
                try {
                    $graphVersion = config('services.meta.graph_version', 'v21.0');
                    Http::post(
                        "https://graph.facebook.com/{$graphVersion}/{$account->page_id}/subscribed_apps",
                        [
                            'subscribed_fields' => 'messages,messaging_postbacks',
                            'access_token' => $account->page_access_token,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::error('Paymob approve: Messenger subscription error', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } elseif ($order->isInstagram()) {
            /** @var InstagramItem|null $instagramItem */
            $instagramItem = $order->instagramItem;
            if ($instagramItem) {
                $instagramItem->update([
                    'status' => 'active',
                    'start_date' => $fromDate->toDateString(),
                    'end_date' => $toDate->toDateString(),
                    'msg_number' => ((int) $instagramItem->msg_number) + $msgsToAdd,
                ]);

                if ($instagramItem->page_id && $instagramItem->access_token) {
                    try {
                        $graphVersion = config('services.meta.graph_version', 'v21.0');
                        Http::post(
                            "https://graph.facebook.com/{$graphVersion}/{$instagramItem->page_id}/subscribed_apps",
                            [
                                'subscribed_fields' => 'messages,messaging_postbacks,message_reads',
                                'access_token' => $instagramItem->access_token,
                            ]
                        );
                    } catch (\Throwable $e) {
                        Log::error('Paymob approve: Instagram subscription error', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        Log::info("Paymob Order #{$order->id} approved and activated", [
            'channel' => $order->channel,
            'transaction_id' => $order->transaction_id,
            'user_id' => $order->user_id,
        ]);

        return $order->fresh();
    }

    /**
     * Verify Paymob HMAC signature.
     */
    protected function verifyPaymobHmac(Request $request, string $hmacSecret): bool
    {
        $receivedHmac = $request->input('hmac') ?? $request->input('obj.hmac');
        if (empty($receivedHmac)) {
            return true;
        }

        $data = $request->input('obj') ?? $request->all();

        $keys = [
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
            'order',
            'owner',
            'pending',
            'source_data.pan',
            'source_data.sub_type',
            'source_data.type',
            'success',
        ];

        $concatenated = '';
        foreach ($keys as $key) {
            $val = data_get($data, $key);
            if (is_bool($val)) {
                $concatenated .= $val ? 'true' : 'false';
            } else {
                $concatenated .= (string) $val;
            }
        }

        $calculatedHmac = hash_hmac('sha512', $concatenated, $hmacSecret);

        return hash_equals(strtolower($calculatedHmac), strtolower((string) $receivedHmac));
    }
}
