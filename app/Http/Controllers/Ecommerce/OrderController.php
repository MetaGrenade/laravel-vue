<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Support\Commerce\CustomerOrders;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request, CustomerOrders $customerOrders): Response
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
        ]);
    }
}
