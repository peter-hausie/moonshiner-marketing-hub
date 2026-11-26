<?php

namespace Database\Factories;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(6),
            'body' => fake()->paragraphs(3, true),
            'context' => fake()->sentence(),
            'content_type' => ContentType::Editorial,
            'status' => ContentStatus::Draft,
            'channels' => ['LinkedIn'],
            'submitted_by' => User::factory(),
        ];
    }
}
