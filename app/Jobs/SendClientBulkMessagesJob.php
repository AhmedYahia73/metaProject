<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendClientBulkMessagesJob implements ShouldQueue
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
    public function handle(): void
    {
        $chunks = array_chunk($this->clientIds, 20);

        Log::info('SendClientBulkMessagesJob: Dispatching chunks', [
            'whats_item_id' => $this->whatsItemId,
            'total_clients' => count($this->clientIds),
            'total_chunks' => count($chunks),
        ]);

        foreach ($chunks as $index => $chunk) {
            $delayMinutes = $index * 5;
            $batchNum = $index + 1;

            $job = new SendClientMessageChunkJob(
                $this->whatsItemId,
                $chunk,
                $this->message
            );

            if ($delayMinutes > 0) {
                $job->delay(now()->addMinutes($delayMinutes));
            }

            dispatch($job);

            Log::info("SendClientBulkMessagesJob: Queued batch #{$batchNum}/".count($chunks), [
                'whats_item_id' => $this->whatsItemId,
                'batch_size' => count($chunk),
                'delay_minutes' => $delayMinutes,
                'scheduled_for' => now()->addMinutes($delayMinutes)->toDateTimeString(),
            ]);
        }
    }
}
