<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class MessengerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'page_id',
        'page_name',
        'page_access_token',
        'verify_token',
        'status',
        'ai_context',
        'ai_file',
        'android_link',
        'ios_link',
        'website_url',
        'msg_number',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'page_access_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
            'msg_number' => 'integer',
        ];
    }

    /**
     * Check whether this Messenger page account is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the restaurant (user) that owns this Messenger account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the orders linked to this Messenger account.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the sent messages linked to this Messenger account.
     *
     * @return HasMany<MsgSend, $this>
     */
    public function msgSends(): HasMany
    {
        return $this->hasMany(MsgSend::class);
    }

    /**
     * Get active subscription details and available message count for this Messenger account.
     *
     * @return array{subscription_status: bool, available_msgs: int, total_msgs: int, used_msgs: int}
     */
    public function getSubscriptionInfo(): array
    {
        $today = Carbon::today()->toDateString();
        $orders = $this->orders()
            ->where('status', 'approved')
            ->where('channel', 'messenger')
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
            ->where('channel', 'messenger')
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
     * Check whether this Messenger account has an active subscription with remaining messages.
     */
    public function hasActiveSubscription(): bool
    {
        return (bool) ($this->getSubscriptionInfo()['subscription_status'] ?? false);
    }
}
