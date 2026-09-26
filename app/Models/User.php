<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'restuarant_name',
        'role',
        'facebook_id',
        'facebook_access_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'facebook_access_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the orders for the user.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the sent messages records for the user.
     *
     * @return HasMany<MsgSend, $this>
     */
    public function msgSends(): HasMany
    {
        return $this->hasMany(MsgSend::class);
    }

    /**
     * Get all linked Facebook Messenger pages for this user (restaurant).
     *
     * @return HasMany<MessengerAccount, $this>
     */
    public function messengerAccounts(): HasMany
    {
        return $this->hasMany(MessengerAccount::class);
    }

    /**
     * Get all WhatsApp items (numbers) for this user (restaurant).
     *
     * @return HasMany<WhatsItem, $this>
     */
    public function whatsItems(): HasMany
    {
        return $this->hasMany(WhatsItem::class);
    }
}
