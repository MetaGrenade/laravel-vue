<?php

namespace App\Http\Controllers\Admin\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalogue\ProductFileRequest;
use App\Models\Product;
use App\Models\ProductFile;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Digital\ProductFiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The files a product delivers, managed from its page. A file is only ever read by the download
 * route, after a paid order's grant has been checked.
 */
class ProductFileController extends Controller
{
    public function __construct(private readonly ProductFiles $files) {}

    /**
     * Add one or more files. Each is stored on its own, so one that cannot be saved does not stop the
     * others: what was added is kept, and the rest are listed with the reason.
     */
    public function store(ProductFileRequest $request, Product $product): RedirectResponse
    {
        /** @var list<UploadedFile> $uploads */
        $uploads = $request->file('files', []);
        $name = count($uploads) === 1 ? $request->validated('name') : null;
        $added = 0;
        $problems = [];

        foreach ($uploads as $upload) {
            try {
                $this->files->add($product, $upload, $name);
                $added++;
            } catch (CatalogueException $exception) {
                $problems[] = $exception->getMessage();

                // No room left or no way to save: the rest would be refused for the same reason.
                break;
            }
        }

        $response = back();

        if ($added > 0) {
            $response->with('success', $added === 1 ? 'File added.' : "{$added} files added.");
        }

        return $problems === [] ? $response : $response->withErrors(['files' => implode("\n", $problems)]);
    }

    public function update(Request $request, ProductFile $file): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->files->update($file, $validated['name'], (bool) $validated['is_active']);

        return back()->with('success', 'File saved.');
    }

    /**
     * Put a new version in place of the file. Everyone who bought the product gets the new one.
     */
    public function replace(Request $request, ProductFile $file): RedirectResponse
    {
        $kilobytes = (int) config('commerce.downloads.max_kilobytes', 102400);

        $request->validate([
            'file' => ['required', 'file', "max:{$kilobytes}"],
        ], [
            'file.max' => 'The file can be at most '.round($kilobytes / 1024, 1).' MB.',
            'file.uploaded' => 'That upload did not arrive, perhaps because it is bigger than the server accepts.',
        ]);

        try {
            $this->files->replace($file, $request->file('file'));
        } catch (CatalogueException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return back()->with('success', 'File replaced.');
    }

    public function destroy(ProductFile $file): RedirectResponse
    {
        $this->files->delete($file);

        return back()->with('success', 'File deleted.');
    }
}
