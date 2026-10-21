<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\ProviderSubscription;
use App\Support\Commerce\CustomerOrders;
use App\Support\Commerce\ProviderSubscriptions;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request, CustomerOrders $customerOrders, ProviderSubscriptions $subscriptions, Entitlements $entitlements): Response
    {
        // Whoever owns the order, not whoever happened to place it.
        $paginator = $customerOrders->for($request->user())
            ->with('items')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        $paginator->setCollection(collect($customerOrders->present($paginator->getCollection())));

        return Inertia::render('commerce/Orders', [
            'orders' => $paginator,
            // Their recurring payments (Tebex's recurring packages), whoever owns them, newest first.
            'subscriptions' => $subscriptions->present(ProviderSubscription::query()->visibleTo($request->user())->with(['order', 'package'])->latest('id')->get()),
            // What their purchases give their account (features switched on, limits raised).
            'entitlements' => $entitlements->held($request->user()),
        ]);
    }
}
