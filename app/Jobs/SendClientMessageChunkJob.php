<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\MsgSend;
use App\Models\WhatsItem;
use App\Services\MetaWhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendClientMessageChunkJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  list<int>  $clientIds
     */
    public function __construct(
        public int $whatsItemId,
        public array $clientIds,
        public string $message
    ) {}

    /**
     * Execute the job.
     */
    public function handle(MetaWhatsAppService $metaService): void
    {
        Log::info('SendClientMessageChunkJob: [STEP 1] Starting batch processing', [
            'whats_item_id' => $this->whatsItemId,
            'client_count' => count($this->clientIds),
        ]);

        $whatsItem = WhatsItem::find($this->whatsItemId);
        if (! $whatsItem) {
            Log::error('SendClientMessageChunkJob: [ERROR] WhatsItem not found, terminating batch', [
                'whats_item_id' => $this->whatsItemId,
            ]);

            return;
        }

        Log::info('SendClientMessageChunkJob: [STEP 2] WhatsItem loaded', [
            'whats_item_id' => $whatsItem->id,
            'phone' => $whatsItem->phone,
            'available_t_msg_number' => (int) $whatsItem->t_msg_number,
            't_start_date' => $whatsItem->t_start_date?->toDateString(),
            't_end_date' => $whatsItem->t_end_date?->toDateString(),
        ]);

        // Ensure broadcast template is registered on WABA
        Log::info('SendClientMessageChunkJob: [STEP 3] Ensuring broadcast template exists on Meta WABA', [
            'whats_item_id' => $whatsItem->id,
        ]);
        $templateResult = $metaService->ensureBroadcastTemplate($whatsItem);
        Log::info('SendClientMessageChunkJob: Broadcast template status', [
            'whats_item_id' => $whatsItem->id,
            'template_status' => $templateResult['message'] ?? 'Checked',
        ]);

        $clients = Client::whereIn('id', $this->clientIds)->get();
        Log::info('SendClientMessageChunkJob: [STEP 4] Retrieved clients for sending', [
            'requested_count' => count($this->clientIds),
            'found_count' => $clients->count(),
        ]);

        $successCount = 0;
        $failedCount = 0;

        foreach ($clients as $index => $client) {
            $clientStep = $index + 1;

            // Check quota before sending
            $freshItem = $whatsItem->fresh();
            if (! $freshItem || (int) $freshItem->t_msg_number <= 0) {
                Log::error("SendClientMessageChunkJob: [QUOTA DEPLETED] Quota is 0. Cannot send to client #{$client->id}. Stopping batch.", [
                    'whats_item_id' => $this->whatsItemId,
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'remaining_clients_skipped' => $clients->count() - $index,
                ]);
                break;
            }

            if (empty($client->whats_number)) {
                Log::warning("SendClientMessageChunkJob: [SKIP] Client #{$client->id} has no whats_number, skipping.", [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                ]);
                $failedCount++;

                continue;
            }

            Log::info("SendClientMessageChunkJob: [SENDING {$clientStep}/{$clients->count()}] Sending message to client", [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'whats_number' => $client->whats_number,
                'whats_item_id' => $whatsItem->id,
            ]);

            $result = $metaService->sendBroadcastTemplateMessage(
                $whatsItem,
                $client->whats_number,
                $this->message
            );

            // ONLY deduct quota and record sent message if delivery/send succeeded
            if ($result['success'] ?? false) {
                WhatsItem::withoutEvents(function () use ($whatsItem) {
                    $whatsItem->decrement('t_msg_number');
                });
                $newQuota = (int) $whatsItem->fresh()->t_msg_number;
                $successCount++;

                Log::info("SendClientMessageChunkJob: [SUCCESS] Message successfully delivered to {$client->whats_number}. Quota deducted by 1.", [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'phone' => $client->whats_number,
                    'meta_message_id' => $result['message_id'] ?? null,
                    'remaining_t_msg_number' => $newQuota,
                ]);

                $msgSend = null;
                if ($whatsItem->user_id) {
                    $msgSend = MsgSend::create([
                        'user_id' => $whatsItem->user_id,
                        'whats_item_id' => $whatsItem->id,
                        'channel' => 'whatsapp',
                    ]);
                }

                if (! empty($result['message_id'])) {
                    Cache::put("broadcast_wamid_{$result['message_id']}", [
                        'whats_item_id' => $whatsItem->id,
                        'msg_send_id' => $msgSend?->id,
                    ], now()->addHours(24));
                }
            } else {
                $failedCount++;
                Log::error("SendClientMessageChunkJob: [FAILED] Sending failed for client #{$client->id}. Quota NOT deducted.", [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'phone' => $client->whats_number,
                    'error' => $result['message'] ?? 'Unknown error',
                    'error_details' => $result['error'] ?? null,
                    'remaining_t_msg_number' => (int) $whatsItem->fresh()->t_msg_number,
                ]);
            }
        }

        Log::info('SendClientMessageChunkJob: [STEP 5] Batch processing completed', [
            'whats_item_id' => $this->whatsItemId,
            'total_clients' => $clients->count(),
            'successful' => $successCount,
            'failed' => $failedCount,
            'final_t_msg_number' => (int) $whatsItem->fresh()->t_msg_number,
        ]);
    }
}
