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
    ];

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
}
