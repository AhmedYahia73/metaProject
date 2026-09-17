<?php

namespace App\Models;

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
}
