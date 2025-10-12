<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\OpenApi\Specification;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\JsonResponse;

class ApiDocumentationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $path = Specification::ensureGenerated();

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new FileNotFoundException('Unable to read the generated API specification.');
        }

        return response()->json(json_decode($contents, true));
    }
}
