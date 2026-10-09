<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\ProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Catalogue\ImageRejected;
use App\Support\Commerce\Catalogue\ProductImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * A product's pictures, managed from its page.
 */
class ProductImageController extends Controller
{
    public function __construct(private readonly ProductImages $images) {}

    /**
     * Add one or more pictures. Each is processed on its own, so one that is refused does not stop
     * the others: what was added is kept, and the refused ones are listed with the reason.
     */
    public function store(ProductImageRequest $request, Product $product): RedirectResponse
    {
        $alt = $request->validated('alt');
        $added = 0;
        $problems = [];

        /** @var list<UploadedFile> $files */
        $files = $request->file('images', []);

        foreach ($files as $file) {
            try {
                $this->images->add($product, $file, $alt);
                $added++;
            } catch (ImageRejected $exception) {
                $problems[] = $file->getClientOriginalName().': '.$exception->getMessage();
            } catch (CatalogueException $exception) {
                // No room left: the rest would be refused for the same reason.
                $problems[] = $exception->getMessage();

                break;
            }
        }

        $response = back();

        if ($added > 0) {
            $response->with('success', $added === 1 ? 'Picture added.' : "{$added} pictures added.");
        }

        // One message with a line for each: the form shows a single error per field.
        return $problems === [] ? $response : $response->withErrors(['images' => implode("\n", $problems)]);
    }

    public function update(Request $request, ProductImage $image): RedirectResponse
    {
        $validated = $request->validate(['alt' => ['nullable', 'string', 'max:255']]);

        $this->images->updateAlt($image, $validated['alt'] ?? null);

        return back()->with('success', 'Description saved.');
    }

    /**
     * Arrange the pictures; the first is the product's main one.
     */
    public function reorder(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        try {
            $this->images->reorder($product, array_map('intval', $validated['ids']));
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Order saved.');
    }

    public function makeMain(ProductImage $image): RedirectResponse
    {
        try {
            $this->images->makeMain($image);
        } catch (CatalogueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Main picture changed.');
    }

    public function destroy(ProductImage $image): RedirectResponse
    {
        $this->images->delete($image);

        return back()->with('success', 'Picture deleted.');
    }
}
