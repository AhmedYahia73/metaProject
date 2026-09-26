<?php

namespace App\Models;

use Database\Factories\WhatsItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsItem extends Model
{
    /** @use HasFactory<WhatsItemFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'phone_number_id',
        'waba_id',
        'access_token',
        'phone_status',
        'phone_verified_at',
        'android_link',
        'ios_link',
        'msg_number',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'msg_number' => 'integer',
        ];
    }

    /**
     * Check whether this WhatsApp item is active.
     */
    public function isActive(): bool
    {
        return $this->phone_status === 'active';
    }

    /**
     * Check whether this WhatsApp item is verified.
     */
    public function isVerified(): bool
    {
        return in_array($this->phone_status, ['verified', 'active'], true);
    }

    /**
     * Get the restaurant (user) that owns this WhatsApp item.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the orders linked to this WhatsApp item.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
