<?php

namespace App\Http\Controllers\Ecommerce;

use App\Http\Controllers\Controller;
use App\Models\DownloadGrant;
use App\Models\ProductFile;
use App\Support\Commerce\Digital\DownloadDelivery;
use App\Support\Commerce\Digital\DownloadRefused;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a purchased file. The link is signed and short-lived (the order page makes a fresh one each
 * time it is opened), which is what lets a guest, who has no account, use it; the grant is then
 * checked and one of the file's downloads is spent before any of it is read.
 */
class DownloadController extends Controller
{
    public function show(DownloadGrant $grant, ProductFile $file, DownloadDelivery $delivery): StreamedResponse|RedirectResponse
    {
        try {
            $delivery->claim($grant, $file);
        } catch (DownloadRefused $refused) {
            abort($refused->status, $refused->getMessage());
        }

        return $delivery->respond($file);
    }
}
