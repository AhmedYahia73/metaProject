<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Jobs\SendClientBulkMessagesJob;
use App\Models\Client;
use App\Models\WhatsItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ClientController extends Controller
{
    /**
     * Display a listing of clients belonging to the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Client::where('user_id', $request->user()->id)
            ->latest();

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('whats_number', 'like', "%{$search}%");
            });
        }

        $clients = $request->boolean('all')
            ? $query->get()
            : $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => true,
            'data' => $clients,
        ]);
    }

    /**
     * Store a newly created client for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'whats_number' => 'required|string|max:50',
        ]);

        $client = $request->user()->clients()->create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Client created successfully.',
            'data' => $client,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified client.
     */
    public function show(Request $request, Client $client): JsonResponse
    {
        if ($client->user_id !== $request->user()->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'status' => true,
            'data' => $client,
        ]);
    }

    /**
     * Update the specified client.
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        if ($client->user_id !== $request->user()->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'whats_number' => 'sometimes|required|string|max:50',
        ]);

        $client->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Client updated successfully.',
            'data' => $client,
        ]);
    }

    /**
     * Remove the specified client.
     */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        if ($client->user_id !== $request->user()->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], Response::HTTP_FORBIDDEN);
        }

        $client->delete();

        return response()->json([
            'status' => true,
            'message' => 'Client deleted successfully.',
        ]);
    }

    /**
     * Send bulk WhatsApp template messages to selected clients.
     */
    public function sendMessages(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'whats_item_id' => 'required|integer|exists:whats_items,id',
            'client_ids' => 'required|array|min:1',
            'client_ids.*' => 'required|integer|exists:clients,id',
            'msg' => 'required|string',
        ]);

        // 1. Verify WhatsItem belongs to the authenticated user
        $whatsItem = WhatsItem::where('user_id', $user->id)->find($validated['whats_item_id']);
        if (! $whatsItem) {
            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item not found or does not belong to you.',
            ], Response::HTTP_FORBIDDEN);
        }

        // 2. Verify all clients belong to the authenticated user
        $clientIds = array_values(array_unique($validated['client_ids']));
        $clients = Client::where('user_id', $user->id)
            ->whereIn('id', $clientIds)
            ->get();

        if ($clients->count() !== count($clientIds)) {
            return response()->json([
                'status' => false,
                'message' => 'Some selected clients do not belong to your account.',
            ], Response::HTTP_FORBIDDEN);
        }

        // 3. Verify date range: now must be between or equal to t_start_date and t_end_date
        $today = Carbon::today()->toDateString();
        if (empty($whatsItem->t_start_date) || empty($whatsItem->t_end_date)) {
            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item does not have an active template package date range.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($whatsItem->t_start_date->toDateString() > $today || $whatsItem->t_end_date->toDateString() < $today) {
            return response()->json([
                'status' => false,
                'message' => 'The template package has expired or is not yet active for this WhatsApp number.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 4. Verify message quota: WhatsItem.t_msg_number >= count of clients
        $clientsCount = $clients->count();
        if ((int) $whatsItem->t_msg_number < $clientsCount) {
            return response()->json([
                'status' => false,
                'message' => "Insufficient template message balance. Available: {$whatsItem->t_msg_number}, Required: {$clientsCount}.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 5. Dispatch background job
        Log::info('ClientController: User requested bulk message dispatch to clients', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'client_count' => $clientsCount,
        ]);

        SendClientBulkMessagesJob::dispatch(
            $whatsItem->id,
            $clients->pluck('id')->all(),
            $validated['msg']
        );

        $chunksCount = (int) ceil($clientsCount / 20);

        return response()->json([
            'status' => true,
            'message' => "Bulk message queued successfully for {$clientsCount} client(s) across {$chunksCount} batch(es).",
            'data' => [
                'total_clients' => $clientsCount,
                'batches_count' => $chunksCount,
                'whats_item_id' => $whatsItem->id,
                'available_t_msg_number' => (int) $whatsItem->t_msg_number,
            ],
        ], Response::HTTP_ACCEPTED);
    }
}
