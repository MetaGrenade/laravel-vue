<?php

use App\Support\OpenApi\Specification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('api:docs', function () {
    $path = Specification::generate();

    $this->info('OpenAPI specification written to: '.$path);
})->purpose('Generate the OpenAPI specification for the JSON API');
