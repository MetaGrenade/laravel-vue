<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} API Reference</title>
    <style>
        body { margin: 0; background-color: #f9fafb; }
        #swagger-ui { max-width: 1200px; margin: 0 auto; padding: 2rem; }
    </style>
    {{-- Swagger UI is part of the build: served from this site, with the policy's nonce on its tags (docs/security-headers.md). --}}
    @vite('resources/js/api-docs.ts')
</head>
<body>
<div id="swagger-ui" data-spec-url="{{ route('api.docs.schema') }}"></div>
</body>
</html>
