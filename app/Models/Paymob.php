<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paymob extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'type',
        'callback',
        'api_key',
        'iframe_id',
        'integration_id',
        'Hmac',
        'logo',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        'logo_url',
    ];

    /**
     * Get the full URL for the logo.
     */
    public function getLogoAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return asset('storage/'.$value);
    }

    /**
     * Alias accessor for logo link.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo;
    }
}
