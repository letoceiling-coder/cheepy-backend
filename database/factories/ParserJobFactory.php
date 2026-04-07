<?php

namespace Database\Factories;

use App\Models\ParserJob;
use App\Support\ParserJobOptions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParserJob>
 */
class ParserJobFactory extends Factory
{
    protected $model = ParserJob::class;

    public function definition(): array
    {
        return [
            'type' => 'menu_only',
            'status' => 'pending',
            'options' => ParserJobOptions::buildFromSettings(),
        ];
    }
}
