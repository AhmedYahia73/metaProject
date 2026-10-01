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
        'start_date',
        'end_date',
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
            'start_date' => 'date',
            'end_date' => 'date',
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
     * @return array{subscription_status: bool, available_msgs: int, start_date: ?string, end_date: ?string, total_msgs: int, used_msgs: int}
     */
    public function getSubscriptionInfo(): array
    {
        $today = Carbon::today()->toDateString();
        $isSubscribed = $this->hasActiveSubscription();

        if (! empty($this->start_date) && ! empty($this->end_date)) {
            return [
                'subscription_status' => $isSubscribed,
                'available_msgs' => max(0, (int) $this->msg_number),
                'start_date' => $this->start_date?->toDateString(),
                'end_date' => $this->end_date?->toDateString(),
                'total_msgs' => max(0, (int) $this->msg_number),
                'used_msgs' => 0,
            ];
        }

        $order = $this->orders()
            ->where('from', '<=', $today)
            ->where('to', '>=', $today)
            ->where(function ($q) {
                $q->where('status', 'approved')
                    ->orWhere(fn ($sq) => $sq->whereNotNull('from')->whereNotNull('to'));
            })
            ->latest('id')
            ->first();

        if (! $order && $this->user_id) {
            $hasMultipleAccounts = MessengerAccount::where('user_id', $this->user_id)->count() > 1;
            if (! $hasMultipleAccounts) {
                $order = Order::where('user_id', $this->user_id)
                    ->whereNull('messenger_account_id')
                    ->where('from', '<=', $today)
                    ->where('to', '>=', $today)
                    ->where(function ($q) {
                        $q->where('channel', 'messenger')
                            ->orWhereNull('channel')
                            ->orWhereHas('package', fn ($pq) => $pq->whereIn('type', ['face', 'all']));
                    })
                    ->latest('id')
                    ->first();
            }
        }

        $available = $order ? ((int) $this->msg_number > 0 ? (int) $this->msg_number : (int) $order->msgs) : 0;

        return [
            'subscription_status' => $isSubscribed,
            'available_msgs' => max(0, $available),
            'start_date' => $order?->from ? Carbon::parse($order->from)->toDateString() : null,
            'end_date' => $order?->to ? Carbon::parse($order->to)->toDateString() : null,
            'total_msgs' => max(0, $available),
            'used_msgs' => 0,
        ];
    }

    /**
     * Check whether this Messenger account has an active subscription with remaining messages.
     */
    public function hasActiveSubscription(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $today = Carbon::today()->toDateString();

        if (! empty($this->start_date) && ! empty($this->end_date)) {
            $isWithinDates = $this->start_date->toDateString() <= $today
                && $this->end_date->toDateString() >= $today;

            return $isWithinDates && ((int) $this->msg_number > 0);
        }

        $order = $this->orders()
            ->where('from', '<=', $today)
            ->where('to', '>=', $today)
            ->where(function ($q) {
                $q->where('status', 'approved')
                    ->orWhere(fn ($sq) => $sq->whereNotNull('from')->whereNotNull('to'));
            })
            ->latest('id')
            ->first();

        if (! $order && $this->user_id) {
            $hasMultipleAccounts = MessengerAccount::where('user_id', $this->user_id)->count() > 1;
            if (! $hasMultipleAccounts) {
                $order = Order::where('user_id', $this->user_id)
                    ->whereNull('messenger_account_id')
                    ->where('from', '<=', $today)
                    ->where('to', '>=', $today)
                    ->where(function ($q) {
                        $q->where('channel', 'messenger')
                            ->orWhereNull('channel')
                            ->orWhereHas('package', fn ($pq) => $pq->whereIn('type', ['face', 'all']));
                    })
                    ->latest('id')
                    ->first();
            }
        }

        if ($order) {
            $available = (int) $this->msg_number > 0 ? (int) $this->msg_number : (int) $order->msgs;
            if ($available > 0) {
                if ($this->msg_number <= 0 || empty($this->start_date) || empty($this->end_date)) {
                    $this->update([
                        'start_date' => $this->start_date ?: $order->from,
                        'end_date' => $this->end_date ?: $order->to,
                        'msg_number' => (int) $this->msg_number > 0 ? $this->msg_number : $order->msgs,
                    ]);
                }

                return true;
            }
        }

        return false;
    }
}
