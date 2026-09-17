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
}
