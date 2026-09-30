<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class InstagramItem extends Model
{
    use HasFactory;

    protected $table = 'instagram_items';

    protected $fillable = [
        'user_id',
        'instagram_id',
        'username',
        'name',
        'profile_picture_url',
        'page_id',
        'access_token',
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
            'status' => 'string',
            'msg_number' => 'integer',
        ];
    }

    /**
     * Check whether this Instagram account is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the restaurant (user) that owns this Instagram account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the orders linked to this Instagram account.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'instagram_item_id');
    }

    /**
     * Get the sent messages linked to this Instagram account.
     *
     * @return HasMany<MsgSend, $this>
     */
    public function msgSends(): HasMany
    {
        return $this->hasMany(MsgSend::class, 'instagram_item_id');
    }

    /**
     * Get the chats linked to this Instagram account.
     *
     * @return HasMany<Chat, $this>
     */
    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class, 'instagram_item_id');
    }

    /**
     * Get active subscription details and available message count for this Instagram account.
     *
     * @return array{subscription_status: bool, available_msgs: int, total_msgs: int, used_msgs: int}
     */
    public function getSubscriptionInfo(): array
    {
        $today = Carbon::today()->toDateString();
        $orders = $this->orders()
            ->where('status', 'approved')
            ->where('channel', 'instagram')
            ->where('from', '<=', $today)
            ->where('to', '>=', $today);

        $totalMsgs = (int) (clone $orders)->sum('msgs');
        if ($totalMsgs <= 0) {
            $hasDirectQuota = (int) $this->msg_number > 0;

            return [
                'subscription_status' => $hasDirectQuota && $this->status === 'active',
                'available_msgs' => (int) $this->msg_number,
                'total_msgs' => (int) $this->msg_number,
                'used_msgs' => 0,
            ];
        }

        $minDate = (clone $orders)->min('from');
        $maxDate = (clone $orders)->max('to');

        $usedMsgs = $this->msgSends()
            ->where('channel', 'instagram')
            ->whereDate('created_at', '>=', $minDate)
            ->whereDate('created_at', '<=', $maxDate)
            ->count();

        $available = max(0, $totalMsgs - $usedMsgs) + (int) $this->msg_number;

        return [
            'subscription_status' => $available > 0 && $this->status === 'active',
            'available_msgs' => $available,
            'total_msgs' => $totalMsgs + (int) $this->msg_number,
            'used_msgs' => $usedMsgs,
        ];
    }

    /**
     * Check whether this Instagram account has an active subscription with remaining messages.
     */
    public function hasActiveSubscription(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ((int) $this->msg_number > 0) {
            return true;
        }

        return (bool) ($this->getSubscriptionInfo()['subscription_status'] ?? false);
    }
}
