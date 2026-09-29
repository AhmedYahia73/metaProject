<?php

namespace App\Models;

use Database\Factories\WhatsItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        'website_url',
        'msg_number',
        'ai_context',
        'ai_file',
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

    /**
     * Get the sent messages linked to this WhatsApp item.
     *
     * @return HasMany<MsgSend, $this>
     */
    public function msgSends(): HasMany
    {
        return $this->hasMany(MsgSend::class);
    }

    /**
     * Get active subscription details and available message count for this WhatsApp item.
     *
     * @return array{subscription_status: bool, available_msgs: int, total_msgs: int, used_msgs: int}
     */
    public function getSubscriptionInfo(): array
    {
        $today = Carbon::today()->toDateString();
        $orders = $this->orders()
            ->where('status', 'approved')
            ->where('channel', 'whatsapp')
            ->where('from', '<=', $today)
            ->where('to', '>=', $today);

        $totalMsgs = (int) (clone $orders)->sum('msgs');
        if ($totalMsgs <= 0) {
            return [
                'subscription_status' => false,
                'available_msgs' => 0,
                'total_msgs' => 0,
                'used_msgs' => 0,
            ];
        }

        $minDate = (clone $orders)->min('from');
        $maxDate = (clone $orders)->max('to');

        $usedMsgs = $this->msgSends()
            ->where('channel', 'whatsapp')
            ->whereDate('created_at', '>=', $minDate)
            ->whereDate('created_at', '<=', $maxDate)
            ->count();

        $available = max(0, $totalMsgs - $usedMsgs);

        return [
            'subscription_status' => $available > 0,
            'available_msgs' => $available,
            'total_msgs' => $totalMsgs,
            'used_msgs' => $usedMsgs,
        ];
    }

    /**
     * Check whether this WhatsApp item has an active subscription with remaining messages.
     */
    public function hasActiveSubscription(): bool
    {
        return (bool) ($this->getSubscriptionInfo()['subscription_status'] ?? false);
    }
}
