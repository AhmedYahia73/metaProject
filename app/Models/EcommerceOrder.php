<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcommerceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'whats_item_id',
        'platform',
        'external_order_id',
        'order_number',
        'customer_name',
        'customer_phone',
        'total_price',
        'currency',
        'status',
        'meta_message_id',
        'raw_payload',
        'confirmed_at',
        'cancelled_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'total_price' => 'float',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Get the user (store owner) that owns this ecommerce order.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the WhatsApp account linked to this ecommerce order.
     *
     * @return BelongsTo<WhatsItem, $this>
     */
    public function whatsItem(): BelongsTo
    {
        return $this->belongsTo(WhatsItem::class);
    }
}
