<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyToken extends Model
{
    use HasFactory;

    protected $table = 'daily_tokens';

    protected $fillable = [
        'date',
        'tokens',
        'whats_item_id',
        'messenger_account_id',
        'instagram_item_id',
        'telegram_account_id',
        'tiktok_account_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'tokens' => 'integer',
        ];
    }

    /**
     * Get the WhatsApp item associated with this daily token record.
     *
     * @return BelongsTo<WhatsItem, $this>
     */
    public function whatsItem(): BelongsTo
    {
        return $this->belongsTo(WhatsItem::class);
    }

    /**
     * Get the Messenger account associated with this daily token record.
     *
     * @return BelongsTo<MessengerAccount, $this>
     */
    public function messengerAccount(): BelongsTo
    {
        return $this->belongsTo(MessengerAccount::class);
    }

    /**
     * Get the Instagram item associated with this daily token record.
     *
     * @return BelongsTo<InstagramItem, $this>
     */
    public function instagramItem(): BelongsTo
    {
        return $this->belongsTo(InstagramItem::class);
    }

    /**
     * Get the Telegram account associated with this daily token record.
     *
     * @return BelongsTo<TelegramAccount, $this>
     */
    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }

    /**
     * Get the TikTok account associated with this daily token record.
     *
     * @return BelongsTo<TikTokAccount, $this>
     */
    public function tiktokAccount(): BelongsTo
    {
        return $this->belongsTo(TikTokAccount::class);
    }

    /**
     * Record daily consumed tokens for a WhatsApp item.
     */
    public static function recordForWhats(int $whatsItemId, int $tokens): self
    {
        $today = now()->toDateString();

        $record = self::where('whats_item_id', $whatsItemId)
            ->whereDate('date', $today)
            ->first();

        if (! $record) {
            $record = self::create([
                'date' => $today,
                'whats_item_id' => $whatsItemId,
                'tokens' => 0,
            ]);
        }

        if ($tokens > 0) {
            $record->increment('tokens', $tokens);
        }

        return $record;
    }

    /**
     * Record daily consumed tokens for a Messenger account.
     */
    public static function recordForMessenger(int $messengerAccountId, int $tokens): self
    {
        $today = now()->toDateString();

        $record = self::where('messenger_account_id', $messengerAccountId)
            ->whereDate('date', $today)
            ->first();

        if (! $record) {
            $record = self::create([
                'date' => $today,
                'messenger_account_id' => $messengerAccountId,
                'tokens' => 0,
            ]);
        }

        if ($tokens > 0) {
            $record->increment('tokens', $tokens);
        }

        return $record;
    }

    /**
     * Record daily consumed tokens for an Instagram item.
     */
    public static function recordForInstagram(int $instagramItemId, int $tokens): self
    {
        $today = now()->toDateString();

        $record = self::where('instagram_item_id', $instagramItemId)
            ->whereDate('date', $today)
            ->first();

        if (! $record) {
            $record = self::create([
                'date' => $today,
                'instagram_item_id' => $instagramItemId,
                'tokens' => 0,
            ]);
        }

        if ($tokens > 0) {
            $record->increment('tokens', $tokens);
        }

        return $record;
    }

    /**
     * Record daily consumed tokens for a Telegram account.
     */
    public static function recordForTelegram(int $telegramAccountId, int $tokens): self
    {
        $today = now()->toDateString();

        $record = self::where('telegram_account_id', $telegramAccountId)
            ->whereDate('date', $today)
            ->first();

        if (! $record) {
            $record = self::create([
                'date' => $today,
                'telegram_account_id' => $telegramAccountId,
                'tokens' => 0,
            ]);
        }

        if ($tokens > 0) {
            $record->increment('tokens', $tokens);
        }

        return $record;
    }

    /**
     * Record daily consumed tokens for a TikTok account.
     */
    public static function recordForTikTok(int $tiktokAccountId, int $tokens): self
    {
        $today = now()->toDateString();

        $record = self::where('tiktok_account_id', $tiktokAccountId)
            ->whereDate('date', $today)
            ->first();

        if (! $record) {
            $record = self::create([
                'date' => $today,
                'tiktok_account_id' => $tiktokAccountId,
                'tokens' => 0,
            ]);
        }

        if ($tokens > 0) {
            $record->increment('tokens', $tokens);
        }

        return $record;
    }
}
