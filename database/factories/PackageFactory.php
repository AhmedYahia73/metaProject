<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => [
                'en' => 'Gold Plan',
                'ar' => 'الباقة الذهبية',
            ],
            'msg_number' => 1000,
            'price' => 99.99,
            'months' => 1,
            'discount_id' => null,
            'tax_id' => null,
        ];
    }
}
