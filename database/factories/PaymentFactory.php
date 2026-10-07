<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'stripe',
            'provider_reference' => 'cs_test_'.Str::lower(Str::random(24)),
            'status' => PaymentStatus::Pending,
            'amount' => '0.00',
            'currency' => config('commerce.currency'),
        ];
    }
}
