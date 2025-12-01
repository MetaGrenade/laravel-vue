<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::query()
            ->with(['variants.prices', 'prices'])
            ->orderBy('name')
            ->paginate(12)
            ->through(function (Product $product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'is_active' => $product->is_active,
                    'variants' => $product->variants,
                    'prices' => $product->prices,
                ];
            });

        return Inertia::render('commerce/Catalog', [
            'products' => $products,
        ]);
    }

    public function show(Product $product): Response
    {
        $product->load(['options.values', 'variants.prices', 'prices', 'inventoryItems']);

        return Inertia::render('commerce/ProductDetail', [
            'product' => $product,
        ]);
    }
}
