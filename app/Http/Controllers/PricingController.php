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
        $featureMatrix = $this->featureMatrix();
        $planLimits = $this->planLimits();

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
                'feature_groups' => $featureMatrix[$plan->slug] ?? [],
                'limits' => $planLimits[$plan->slug] ?? [],
                'stripe_price_id' => $plan->stripe_price_id,
            ])->values();

        return Inertia::render('Pricing', [
            'plans' => $plans,
            'faqs' => $this->faqs(),
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

    /**
     * Feature matrix keyed by plan slug so the front end can render comparison tables.
     */
    protected function featureMatrix(): array
    {
        return [
            'starter' => [
                [
                    'title' => 'Community & Support',
                    'items' => [
                        [
                            'key' => 'community',
                            'label' => 'Community forums',
                            'value' => 'Full access with moderation tools',
                        ],
                        [
                            'key' => 'office-hours',
                            'label' => 'Office hours',
                            'value' => 'Monthly live Q&A',
                        ],
                        [
                            'key' => 'support',
                            'label' => 'Support response',
                            'value' => 'Within 2 business days',
                        ],
                    ],
                ],
                [
                    'title' => 'Collaboration',
                    'items' => [
                        [
                            'key' => 'team-seats',
                            'label' => 'Team seats',
                            'value' => 'Up to 3 seats included',
                        ],
                        [
                            'key' => 'projects',
                            'label' => 'Projects',
                            'value' => '2 active projects',
                        ],
                        [
                            'key' => 'templates',
                            'label' => 'Template library',
                            'value' => 'Starter templates',
                        ],
                    ],
                ],
            ],
            'pro' => [
                [
                    'title' => 'Community & Support',
                    'items' => [
                        [
                            'key' => 'community',
                            'label' => 'Community forums',
                            'value' => 'Full access with analytics',
                        ],
                        [
                            'key' => 'office-hours',
                            'label' => 'Office hours',
                            'value' => 'Weekly live coaching',
                        ],
                        [
                            'key' => 'support',
                            'label' => 'Support response',
                            'value' => 'Same-day priority support',
                        ],
                    ],
                ],
                [
                    'title' => 'Collaboration',
                    'items' => [
                        [
                            'key' => 'team-seats',
                            'label' => 'Team seats',
                            'value' => 'Up to 10 seats included',
                            'note' => 'Add more seats at $9/seat',
                        ],
                        [
                            'key' => 'projects',
                            'label' => 'Projects',
                            'value' => 'Unlimited active projects',
                            'badge' => 'Popular',
                        ],
                        [
                            'key' => 'templates',
                            'label' => 'Template library',
                            'value' => 'Full library + beta releases',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Soft limits and quotas per plan.
     */
    protected function planLimits(): array
    {
        return [
            'starter' => [
                [
                    'label' => 'Monthly member invites',
                    'value' => 'Up to 250',
                ],
                [
                    'label' => 'File storage',
                    'value' => '25 GB pooled',
                ],
                [
                    'label' => 'Automation runs',
                    'value' => '2,000 per month',
                ],
            ],
            'pro' => [
                [
                    'label' => 'Monthly member invites',
                    'value' => 'Unlimited',
                    'helper' => 'Fair-use policy applies',
                ],
                [
                    'label' => 'File storage',
                    'value' => '250 GB pooled',
                ],
                [
                    'label' => 'Automation runs',
                    'value' => '25,000 per month',
                ],
            ],
        ];
    }

    protected function faqs(): array
    {
        return [
            [
                'question' => 'Can I change my plan later?',
                'answer' => 'Yes. You can upgrade or downgrade at any time from your billing settings and the change is applied immediately.',
            ],
            [
                'question' => 'Do you offer trials?',
                'answer' => 'We start billing only when you add a payment method. Cancel anytime during the first 14 days for a full refund.',
            ],
            [
                'question' => 'How do seat limits work?',
                'answer' => 'Each plan includes a set number of seats. You can invite more teammates and we will automatically pro-rate any additional seats.',
            ],
            [
                'question' => 'Is my payment information secure?',
                'answer' => 'Yes. We never touch card details directly—checkout is processed securely by Stripe with industry-standard encryption.',
            ],
        ];
    }
}
