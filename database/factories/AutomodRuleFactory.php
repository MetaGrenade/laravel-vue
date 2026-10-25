<?php

namespace Database\Factories;

use App\Models\AutomodRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomodRule>
 */
class AutomodRuleFactory extends Factory
{
    protected $model = AutomodRule::class;

    public function definition(): array
    {
        return [
            'name' => 'Cheap watches',
            'type' => AutomodRule::WORDS,
            'settings' => ['phrases' => ['cheap watches'], 'whole_words' => false],
            'action' => AutomodRule::HOLD,
            'only_new_days' => null,
            'is_enabled' => true,
        ];
    }

    /**
     * @param  list<string>  $phrases
     */
    public function words(array $phrases, bool $wholeWords = false): static
    {
        return $this->state(fn () => ['name' => 'Words: '.implode(', ', $phrases), 'type' => AutomodRule::WORDS, 'settings' => ['phrases' => $phrases, 'whole_words' => $wholeWords]]);
    }

    /**
     * @param  list<string>  $blockedDomains
     */
    public function links(?int $max = null, array $blockedDomains = []): static
    {
        return $this->state(fn () => ['name' => 'Links', 'type' => AutomodRule::LINKS, 'settings' => ['max_links' => $max, 'blocked_domains' => $blockedDomains]]);
    }

    public function duplicates(int $minutes = 60, int $copies = 1): static
    {
        return $this->state(fn () => ['name' => 'Repeats', 'type' => AutomodRule::DUPLICATE, 'settings' => ['minutes' => $minutes, 'copies' => $copies]]);
    }

    public function rate(int $posts = 5, int $minutes = 10): static
    {
        return $this->state(fn () => ['name' => 'Too fast', 'type' => AutomodRule::RATE, 'settings' => ['posts' => $posts, 'minutes' => $minutes]]);
    }

    public function rejecting(): static
    {
        return $this->state(fn () => ['action' => AutomodRule::REJECT]);
    }

    public function onlyForNewMembers(int $days = 7): static
    {
        return $this->state(fn () => ['only_new_days' => $days]);
    }

    public function off(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }
}
