<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'messenger_account_id',
        'whats_item_id',
        'name',
        'phone',
        'message',
        'is_image',
        'is_admin',
        'is_read',
        'read_at',
        'channel',
        'messenger_sender_id',
        'sender_type',
        'meta_message_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_image' => 'boolean',
            'is_admin' => 'boolean',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the restaurant (user) this chat belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the Facebook Messenger account associated with this message.
     *
     * @return BelongsTo<MessengerAccount, $this>
     */
    public function messengerAccount(): BelongsTo
    {
        return $this->belongsTo(MessengerAccount::class);
    }

    /**
     * Get the WhatsApp item (number) associated with this message.
     *
     * @return BelongsTo<WhatsItem, $this>
     */
    public function whatsItem(): BelongsTo
    {
        return $this->belongsTo(WhatsItem::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    public function isCustomer(): bool
    {
        return $this->sender_type === 'customer' || ! $this->is_admin;
    }

    public function isBot(): bool
    {
        return $this->sender_type === 'bot';
    }

    public function isAgent(): bool
    {
        return $this->sender_type === 'agent';
    }

    /**
     * Mark this message as read.
     */
    public function markAsRead(): bool
    {
        if ($this->is_read) {
            return false;
        }

        return $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Scope query to unread customer messages.
     *
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false)->where('is_admin', false);
    }

    /**
     * Scope query to customer messages only.
     *
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    public function scopeCustomer(Builder $query): Builder
    {
        return $query->where('is_admin', false);
    }
}
