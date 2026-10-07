<?php

namespace Database\Factories;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'currency' => config('commerce.currency'),
            'subtotal' => '0.00',
            'tax_total' => '0.00',
            'shipping_total' => '0.00',
            'discount_total' => '0.00',
            'grand_total' => '0.00',
            'customer_email' => fake()->safeEmail(),
            'placed_at' => now(),
        ];
    }

    /**
     * An order placed by (and owned by) the given user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'owner_type' => $user->getMorphClass(),
            'owner_id' => $user->id,
            'customer_email' => $user->email,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Processing,
            'payment_status' => OrderPaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
