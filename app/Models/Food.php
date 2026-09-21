<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Food extends Model
{
    use HasFactory;

    protected $table = 'food';

    /**
     * The database connection name for the model.
     *
     * @var string|null
     */
    protected $connection = 'keeto';

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return config('database.second_connection', env('DB_SECOND_CONNECTION', $this->connection));
    }

    protected $fillable = [
        'name_ar',
        'description_ar',
        'start_time',
        'end_time',
        'price',
        'discount_type',
        'discount_value',
        'is_out_of_stock',
        'restaurantid ',
        'status',
    ];
}
