<?php

namespace Database\Factories;

use App\Models\BillingWebhookCall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingWebhookCall>
 */
class BillingWebhookCallFactory extends Factory
{
    protected $model = BillingWebhookCall::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'stripe_id' => 'evt_'.Str::lower(Str::random(26)),
            'type' => 'invoice.payment_succeeded',
            'payload' => ['object' => 'event'],
            'processed_at' => now(),
        ];
    }
}
