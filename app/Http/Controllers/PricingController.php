<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithStripe;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Billing\PlanPresenter;
use App\Support\Billing\PromotionCodes;
use App\Support\Billing\SubscriptionManager;
use App\Support\Commerce\GuestCarryOver;
use App\Support\Seo\Seo;
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

    public function __construct(protected SubscriptionManager $subscriptions) {}

    public function index(Request $request): InertiaResponse
    {
        $plans = SubscriptionPlan::query()
            ->active()
            ->with('entitlementLinks.entitlement')
            ->orderBy('price')
            ->get()
            ->map(fn (SubscriptionPlan $plan) => PlanPresenter::forPage($plan))
            ->values();

        app(Seo::class)
            ->title('Pricing')
            ->description('Simple, transparent pricing. Compare plans and pick the one that fits your team.');

        return Inertia::render('Pricing', [
            'plans' => $plans,
            // Whether the trials the plans offer are for this visitor: a customer gets one, and a visitor has had none.
            'trial_eligible' => $this->subscriptions->eligibleForTrial($request->user()),
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
            app(GuestCarryOver::class)->remember($request);
            Auth::login($user);
            app(GuestCarryOver::class)->afterSignIn($user, $request);
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

    /**
     * What a promotion code takes off the chosen plan, or why it cannot be used. It comes after {@see intent()}, which is what
     * gives a new visitor their account, so there is somebody to check it for.
     */
    public function promotionCode(Request $request, PromotionCodes $codes): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'You need an account before using a code.',
            ], 401);
        }

        $data = Validator::make($request->all(), [
            'plan_id' => ['required', 'exists:subscription_plans,id'],
            'promotion_code' => ['required', 'string', 'max:64'],
        ])->validate();

        $plan = SubscriptionPlan::query()->active()->findOrFail($data['plan_id']);

        return response()->json($codes->find($user, $plan, $data['promotion_code'])->toArray());
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
            'promotion_code' => ['nullable', 'string', 'max:64'],
        ])->validate();

        /** @var SubscriptionPlan $plan */
        $plan = SubscriptionPlan::query()->active()->findOrFail($data['plan_id']);

        try {
            $subscription = $this->subscriptions->create($user, $plan, $data['payment_method'], [
                'promotion_code' => $data['promotion_code'] ?? null,
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
