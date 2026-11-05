<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\EcommerceOrder;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EcommerceOrderController extends Controller
{
    public function __construct(
        protected MetaWhatsAppService $metaService
    ) {}

    /**
     * Display a paginated listing of ecommerce orders for the authenticated store user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = EcommerceOrder::with(['whatsItem:id,phone,website_url'])
            ->where('user_id', $user->id)
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('whats_item_id')) {
            $query->where('whats_item_id', $request->input('whats_item_id'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('external_order_id', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $perPage = $request->integer('per_page', 15);
        $orders = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Display the specified ecommerce order.
     */
    public function show(Request $request, EcommerceOrder $ecommerceOrder): JsonResponse
    {
        if ($ecommerceOrder->user_id !== $request->user()->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'status' => true,
            'data' => $ecommerceOrder->load('whatsItem:id,phone,website_url'),
        ]);
    }

    /**
     * Resend order confirmation WhatsApp template to customer.
     */
    public function resend(Request $request, EcommerceOrder $ecommerceOrder): JsonResponse
    {
        if ($ecommerceOrder->user_id !== $request->user()->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], Response::HTTP_FORBIDDEN);
        }

        $whatsItem = $ecommerceOrder->whatsItem;
        if (! $whatsItem || ! $whatsItem->hasActiveSubscription()) {
            return response()->json([
                'status' => false,
                'message' => 'WhatsApp number is not active or messages are depleted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->metaService->sendOrderConfirmationMessage(
            $whatsItem,
            $ecommerceOrder->customer_phone,
            $ecommerceOrder,
            $request->user()->restuarant_name ?: $request->user()->name
        );

        return response()->json([
            'status' => $result['success'],
            'message' => $result['message'],
            'data' => $result,
        ]);
    }
}
