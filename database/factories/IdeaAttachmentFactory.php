<?php

namespace Database\Factories;

use App\Models\Idea;
use App\Models\IdeaAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdeaAttachment>
 */
class IdeaAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = 'pdf';
        $filename = $this->faker->word().'.'.$extension;

        return [
            'idea_id' => Idea::factory(),
            'uploaded_by_user_id' => User::factory(),
            'disk' => 'local',
            'path' => 'idea-attachments/testing/'.Str::uuid().'.'.$extension,
            'original_filename' => $filename,
            'extension' => $extension,
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(1024, 1024 * 1024),
        ];
    }
}
