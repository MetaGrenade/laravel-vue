<?php

namespace App\Support\OpenApi;

class Specification
{
    public static function path(): string
    {
        return storage_path('app/api-docs/openapi.json');
    }

    public static function ensureGenerated(): string
    {
        $path = static::path();

        if (! is_file($path)) {
            static::generate($path);
        }

        return $path;
    }

    public static function generate(?string $path = null): string
    {
        $path ??= static::path();

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $payload = json_encode(static::toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents($path, $payload);

        return $path;
    }

    public static function toArray(): array
    {
        $appUrl = config('app.url') ? rtrim(config('app.url'), '/') : url('');

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('app.name').' API',
                'description' => 'HTTP API for interacting with '.config('app.name').'.',
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => $appUrl.'/api',
                ],
            ],
            'paths' => static::paths(),
            'components' => [
                'securitySchemes' => [
                    'sanctum' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Token',
                    ],
                ],
                'schemas' => static::schemas(),
            ],
            'tags' => [
                ['name' => 'Authentication'],
                ['name' => 'Blogs'],
                ['name' => 'Forum Threads'],
                ['name' => 'Profile'],
            ],
        ];
    }

    protected static function paths(): array
    {
        return [
            '/v1/auth/token' => [
                'post' => [
                    'summary' => 'Create an API token',
                    'tags' => ['Authentication'],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['email', 'password'],
                                    'properties' => [
                                        'email' => [
                                            'type' => 'string',
                                            'format' => 'email',
                                            'example' => 'jane@example.com',
                                        ],
                                        'password' => [
                                            'type' => 'string',
                                            'format' => 'password',
                                            'example' => 'secret-password',
                                        ],
                                        'device_name' => [
                                            'type' => 'string',
                                            'example' => 'My iPhone',
                                        ],
                                        'abilities' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Token created',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('TokenResponse'),
                                ],
                            ],
                        ],
                        '422' => static::validationErrorResponse(),
                    ],
                ],
                'delete' => [
                    'summary' => 'Revoke the current API token',
                    'tags' => ['Authentication'],
                    'security' => [['sanctum' => []]],
                    'responses' => [
                        '204' => [
                            'description' => 'Token revoked',
                        ],
                        '401' => static::unauthenticatedResponse(),
                    ],
                ],
            ],
            '/v1/profile' => [
                'get' => [
                    'summary' => 'Get the authenticated user profile',
                    'tags' => ['Profile'],
                    'security' => [['sanctum' => []]],
                    'responses' => [
                        '200' => [
                            'description' => 'Profile information',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('User'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                    ],
                ],
            ],
            '/v1/blogs' => [
                'get' => [
                    'summary' => 'List published blog posts',
                    'tags' => ['Blogs'],
                    'parameters' => [
                        static::paginationQueryParameter(),
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Paginated list of blogs',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('PaginatedBlogCollection'),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/v1/blogs/{slug}' => [
                'get' => [
                    'summary' => 'Show a single blog post',
                    'tags' => ['Blogs'],
                    'parameters' => [
                        [
                            'name' => 'slug',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Blog post details',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('Blog'),
                                ],
                            ],
                        ],
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/forum/threads' => [
                'get' => [
                    'summary' => 'List published forum threads',
                    'tags' => ['Forum Threads'],
                    'parameters' => [
                        static::paginationQueryParameter(),
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Paginated list of threads',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('PaginatedForumThreadCollection'),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/v1/forum/threads/{slug}' => [
                'get' => [
                    'summary' => 'Show a single forum thread',
                    'tags' => ['Forum Threads'],
                    'parameters' => [
                        [
                            'name' => 'slug',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Forum thread details',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThread'),
                                ],
                            ],
                        ],
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
        ];
    }

    protected static function schemas(): array
    {
        return [
            'TokenResponse' => [
                'type' => 'object',
                'properties' => [
                    'token' => ['type' => 'string', 'example' => '1|abc123'],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'user' => static::schemaRef('User'),
                ],
                'required' => ['token', 'user'],
            ],
            'User' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'name' => ['type' => 'string', 'example' => 'Jane Doe'],
                    'email' => ['type' => 'string', 'format' => 'email', 'nullable' => true],
                ],
                'required' => ['id', 'name'],
            ],
            'Blog' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'title' => ['type' => 'string', 'example' => 'Introducing our API'],
                    'slug' => ['type' => 'string', 'example' => 'introducing-our-api'],
                    'excerpt' => ['type' => 'string'],
                    'body' => ['type' => 'string', 'nullable' => true],
                    'cover_image' => ['type' => 'string', 'nullable' => true],
                    'status' => ['type' => 'string', 'example' => 'published'],
                    'published_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'author' => static::schemaRef('User'),
                    'categories' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'required' => ['id', 'title', 'slug', 'excerpt', 'status', 'author'],
            ],
            'ForumThread' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 5],
                    'title' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'excerpt' => ['type' => 'string', 'nullable' => true],
                    'is_locked' => ['type' => 'boolean'],
                    'is_pinned' => ['type' => 'boolean'],
                    'views' => ['type' => 'integer'],
                    'last_posted_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'board' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'slug' => ['type' => 'string'],
                        ],
                    ],
                    'author' => static::schemaRef('User'),
                    'latest_post' => [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'body' => ['type' => 'string'],
                            'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                            'author' => static::schemaRef('User'),
                        ],
                    ],
                ],
                'required' => ['id', 'title', 'slug', 'is_locked', 'is_pinned', 'views', 'author'],
            ],
            'PaginatedBlogCollection' => static::paginatedSchema('Blog'),
            'PaginatedForumThreadCollection' => static::paginatedSchema('ForumThread'),
            'ValidationError' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
                'required' => ['message', 'errors'],
            ],
            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                ],
                'required' => ['message'],
            ],
        ];
    }

    protected static function schemaRef(string $name): array
    {
        return ['$ref' => '#/components/schemas/'.$name];
    }

    protected static function paginationQueryParameter(): array
    {
        return [
            'name' => 'page',
            'in' => 'query',
            'schema' => ['type' => 'integer', 'minimum' => 1],
            'description' => 'Page number of the results to return.',
        ];
    }

    protected static function paginatedSchema(string $resource): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => static::schemaRef($resource),
                ],
                'links' => [
                    'type' => 'object',
                    'properties' => [
                        'first' => ['type' => 'string', 'nullable' => true],
                        'last' => ['type' => 'string', 'nullable' => true],
                        'prev' => ['type' => 'string', 'nullable' => true],
                        'next' => ['type' => 'string', 'nullable' => true],
                    ],
                ],
                'meta' => [
                    'type' => 'object',
                    'properties' => [
                        'current_page' => ['type' => 'integer'],
                        'from' => ['type' => 'integer', 'nullable' => true],
                        'last_page' => ['type' => 'integer'],
                        'path' => ['type' => 'string'],
                        'per_page' => ['type' => 'integer'],
                        'to' => ['type' => 'integer', 'nullable' => true],
                        'total' => ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['data', 'links', 'meta'],
        ];
    }

    protected static function validationErrorResponse(): array
    {
        return [
            'description' => 'Validation error',
            'content' => [
                'application/json' => [
                    'schema' => static::schemaRef('ValidationError'),
                ],
            ],
        ];
    }

    protected static function unauthenticatedResponse(): array
    {
        return [
            'description' => 'Unauthenticated',
            'content' => [
                'application/json' => [
                    'schema' => static::schemaRef('ErrorResponse'),
                ],
            ],
        ];
    }

    protected static function notFoundResponse(): array
    {
        return [
            'description' => 'Resource not found',
            'content' => [
                'application/json' => [
                    'schema' => static::schemaRef('ErrorResponse'),
                ],
            ],
        ];
    }
}
