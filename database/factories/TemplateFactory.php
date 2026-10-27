<?php

namespace Database\Factories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_template' => [
                'groups' => [
                    [
                        'name' => 'General Information',
                        'data' => [
                            [
                                [
                                    'lable' => 'Full Name',
                                    'value' => 'text',
                                ],
                                [
                                    'lable' => 'Age',
                                    'value' => 'numeric',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
