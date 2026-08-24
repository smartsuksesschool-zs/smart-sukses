<?php

namespace Database\Factories;

use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'sender_id' => null,
            'title' => fake()->sentence(4),
            'message' => fake()->paragraph(),
            'type' => NotificationType::Announcement->value,
            'target_type' => NotificationTargetType::All->value,
            'target_id' => null,
            'wa_template' => null,
            'is_draft' => true,
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'is_draft' => false,
            'sent_at' => now(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'is_draft' => true,
            'sent_at' => null,
        ]);
    }
}
