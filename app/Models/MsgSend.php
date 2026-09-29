<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsgSend extends Model
{
    use HasFactory;

    protected $table = 'msg_sends';

    protected $fillable = [
        'user_id',
        'messenger_account_id',
        'whats_item_id',
        'channel',
    ];

    /**
     * Get the user that owns the message send record.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the Messenger account that sent the message.
     *
     * @return BelongsTo<MessengerAccount, $this>
     */
    public function messengerAccount(): BelongsTo
    {
        return $this->belongsTo(MessengerAccount::class);
    }

    /**
     * Get the WhatsApp item that sent the message.
     *
     * @return BelongsTo<WhatsItem, $this>
     */
    public function whatsItem(): BelongsTo
    {
        return $this->belongsTo(WhatsItem::class);
    }
}
