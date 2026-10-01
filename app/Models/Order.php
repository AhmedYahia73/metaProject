<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_id',
        'user_id',
        'total_discount',
        'total_tax',
        'price',
        'final_price',
        'from',
        'to',
        'msgs',
        'status',
        'channel',
        'messenger_account_id',
        'whats_item_id',
        'instagram_item_id',
    ];

    protected static function booted(): void
    {
        static::created(function (Order $order) {
            $hasActiveDates = ! empty($order->from) && ! empty($order->to);
            if ($order->status === 'approved' || $hasActiveDates) {
                $target = $order->messengerAccount ?: ($order->whatsItem ?: $order->instagramItem);

                if (! $target) {
                    if ($order->messenger_account_id) {
                        $target = $order->messengerAccount;
                    } elseif ($order->instagram_item_id) {
                        $target = $order->instagramItem;
                    } elseif ($order->whats_item_id) {
                        $target = $order->whatsItem;
                    } elseif ($order->channel === 'messenger') {
                        $accounts = MessengerAccount::where('user_id', $order->user_id)->get();
                        if ($accounts->count() === 1) {
                            $target = $accounts->first();
                        }
                    } elseif ($order->channel === 'instagram') {
                        $accounts = InstagramItem::where('user_id', $order->user_id)->get();
                        if ($accounts->count() === 1) {
                            $target = $accounts->first();
                        }
                    } elseif ($order->channel === 'whatsapp') {
                        $items = WhatsItem::where('user_id', $order->user_id)->get();
                        if ($items->count() === 1) {
                            $target = $items->first();
                        }
                    } else {
                        $mAccounts = MessengerAccount::where('user_id', $order->user_id)->get();
                        $wItems = WhatsItem::where('user_id', $order->user_id)->get();
                        $iItems = InstagramItem::where('user_id', $order->user_id)->get();
                        $total = $mAccounts->count() + $wItems->count() + $iItems->count();
                        if ($total === 1) {
                            $target = $mAccounts->first() ?: ($wItems->first() ?: $iItems->first());
                        }
                    }
                }

                if ($target) {
                    $update = [];
                    if (empty($target->start_date) && ! empty($order->from)) {
                        $update['start_date'] = $order->from;
                    }
                    if (empty($target->end_date) && ! empty($order->to)) {
                        $update['end_date'] = $order->to;
                    }
                    if ($target->msg_number <= 0 && $order->msgs > 0) {
                        $update['msg_number'] = $order->msgs;
                    }
                    if (! empty($update)) {
                        $target->update($update);
                    }
                }
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_discount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'price' => 'decimal:2',
            'final_price' => 'decimal:2',
            'from' => 'date',
            'to' => 'date',
            'msgs' => 'integer',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the package associated with this order.
     *
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Get the user that owns the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the Messenger account this order activates (null for WhatsApp orders).
     *
     * @return BelongsTo<MessengerAccount, $this>
     */
    public function messengerAccount(): BelongsTo
    {
        return $this->belongsTo(MessengerAccount::class);
    }

    /**
     * Get the WhatsApp item this order activates (null for Messenger/Instagram orders).
     *
     * @return BelongsTo<WhatsItem, $this>
     */
    public function whatsItem(): BelongsTo
    {
        return $this->belongsTo(WhatsItem::class);
    }

    /**
     * Get the Instagram item this order activates.
     *
     * @return BelongsTo<InstagramItem, $this>
     */
    public function instagramItem(): BelongsTo
    {
        return $this->belongsTo(InstagramItem::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeInstagram(Builder $query): Builder
    {
        return $query->where('channel', 'instagram');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isMessenger(): bool
    {
        return $this->channel === 'messenger';
    }

    public function isWhatsApp(): bool
    {
        return $this->channel === 'whatsapp';
    }

    public function isInstagram(): bool
    {
        return $this->channel === 'instagram';
    }
}
