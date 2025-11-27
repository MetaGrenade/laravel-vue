<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithStripe;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\SubscriptionManager;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Cashier\Exceptions\IncompletePayment;

class PricingController extends Controller
{
    use InteractsWithStripe;

    public function __construct(protected SubscriptionManager $subscriptions)
    {
    }

    public function index(): InertiaResponse
    {
        $plans = SubscriptionPlan::query()
            ->active()
            ->orderBy('price')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'price' => $plan->price,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'features' => $plan->features ?? [],
                'stripe_price_id' => $plan->stripe_price_id,
            ])->values();

        return Inertia::render('Pricing', [
            'plans' => $plans,
        ]);
    }

    public function intent(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = Validator::make($request->all(), [
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'email' => [
                $user ? 'sometimes' : 'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
        ])->validate();

        /** @var SubscriptionPlan $plan */
        $plan = SubscriptionPlan::query()->active()->findOrFail($data['plan_id']);

        if (! $user) {
            $user = $this->createUserFromEmail($data['email']);
            Auth::login($user);
            event(new Registered($user));
        }

        $intent = $this->shouldBypassStripe() ? (object) [
            'id' => 'seti_'.Str::random(24),
            'client_secret' => 'seti_secret_'.Str::random(40),
        ] : $user->createSetupIntent();

        return response()->json([
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'nickname' => $user->nickname,
            ],
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
            ],
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'You need an account before subscribing.',
            ], 401);
        }

        $data = Validator::make($request->all(), [
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'payment_method' => ['required', 'string'],
            'coupon' => ['nullable', 'string'],
        ])->validate();

        /** @var SubscriptionPlan $plan */
        $plan = SubscriptionPlan::query()->active()->findOrFail($data['plan_id']);

        try {
            $subscription = $this->subscriptions->create($user, $plan, $data['payment_method'], [
                'coupon' => $data['coupon'] ?? null,
            ]);
        } catch (IncompletePayment $exception) {
            $paymentIntent = $this->extractPaymentIntent($exception);

            return response()->json([
                'status' => 'requires_action',
                'payment_intent_id' => $paymentIntent?->id,
                'client_secret' => $paymentIntent?->client_secret,
            ], 409);
        }

        return response()->json([
            'status' => 'success',
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'stripe_status' => $subscription->stripe_status,
                'stripe_id' => $subscription->stripe_id,
            ] : null,
        ]);
    }

    protected function createUserFromEmail(string $email): User
    {
        $baseNickname = Str::slug(Str::before($email, '@')) ?: 'member';
        $nickname = $baseNickname;
        $counter = 1;

        while (User::where('nickname', $nickname)->exists()) {
            $nickname = $baseNickname.'-'.$counter;
            $counter++;
        }

        return User::create([
            'nickname' => $nickname,
            'email' => $email,
            'password' => Hash::make(Str::random(32)),
        ]);
    }
}
