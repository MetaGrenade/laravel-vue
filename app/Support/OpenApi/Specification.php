<?php

namespace App\Support\OpenApi;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

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
            'paths' => static::withScopes(static::paths()),
            'components' => [
                'securitySchemes' => [
                    'sanctum' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Token',
                        'description' => 'An API key, made in Settings → API keys. A key is limited to the scopes it was given: a request it has no scope for is refused with a 403 that names the scope it lacks. A key that has expired or been revoked is refused with a 401.',
                    ],
                ],
                'schemas' => static::schemas(),
            ],
            'tags' => [
                ['name' => 'Authentication'],
                ['name' => 'Blogs'],
                ['name' => 'Blog Comments'],
                ['name' => 'Polls'],
                ['name' => 'Forum Threads'],
                ['name' => 'Forum Posts'],
                ['name' => 'Forum Moderation'],
                ['name' => 'Profile'],
                ['name' => 'Support'],
            ],
        ];
    }

    protected static function paths(): array
    {
        return [
            '/v1/auth/token' => [
                'post' => [
                    'summary' => 'Create an API token',
                    'description' => static::signInDescription(),
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
            '/v1/blogs/{slug}/comments' => [
                'get' => [
                    'summary' => 'List approved comments for a blog post',
                    'tags' => ['Blog Comments'],
                    'parameters' => [
                        [
                            'name' => 'slug',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                        static::paginationQueryParameter(),
                        [
                            'name' => 'per_page',
                            'in' => 'query',
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                            'description' => 'Number of comments to return per page (max 50).',
                        ],
                        [
                            'name' => 'sort',
                            'in' => 'query',
                            'schema' => [
                                'type' => 'string',
                                'enum' => ['oldest', 'newest', 'top'],
                                'default' => 'oldest',
                            ],
                            'description' => 'Sort comments by oldest (default), newest, or top (most liked).',
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Paginated comments',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('PaginatedBlogCommentCollection'),
                                ],
                            ],
                        ],
                        '404' => static::notFoundResponse(),
                    ],
                ],
                'post' => [
                    'summary' => 'Create a blog comment',
                    'tags' => ['Blog Comments'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        [
                            'name' => 'slug',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['body'],
                                    'properties' => [
                                        'body' => ['type' => 'string', 'maxLength' => 2000],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Comment created',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('BlogComment'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/blogs/{slug}/comments/{comment}' => [
                'patch' => [
                    'summary' => 'Update a blog comment',
                    'tags' => ['Blog Comments'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'comment', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['body'],
                                    'properties' => [
                                        'body' => ['type' => 'string', 'maxLength' => 2000],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated comment',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('BlogComment'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete a blog comment',
                    'tags' => ['Blog Comments'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'comment', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Comment deleted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                            'id' => ['type' => 'integer'],
                                        ],
                                        'required' => ['message', 'id'],
                                    ],
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/blogs/{slug}/comments/{comment}/react' => [
                'post' => [
                    'summary' => 'React to a blog comment',
                    'tags' => ['Blog Comments'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'comment', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['reaction'],
                                    'properties' => [
                                        'reaction' => [
                                            'type' => 'string',
                                            'enum' => ['like', 'dislike', 'none'],
                                            'description' => 'Add, change, or remove a reaction for the authenticated user.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated comment with reactions',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('BlogComment'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/blogs/{slug}/comments/{comment}/report' => [
                'post' => [
                    'summary' => 'Report a blog comment',
                    'tags' => ['Blog Comments'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'comment', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['reason_category'],
                                    'properties' => [
                                        'reason_category' => ['type' => 'string'],
                                        'reason' => ['type' => 'string', 'maxLength' => 1000, 'nullable' => true],
                                        'evidence_url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048, 'nullable' => true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Report submitted',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'message' => ['type' => 'string'],
                                        ],
                                        'required' => ['message'],
                                    ],
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/blogs/{slug}/comments/subscriptions' => [
                'post' => [
                    'summary' => 'Subscribe to blog comment notifications',
                    'tags' => ['Blog Comments'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Subscribed to comments',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('BlogCommentSubscriptionStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
                'delete' => [
                    'summary' => 'Unsubscribe from blog comment notifications',
                    'tags' => ['Blog Comments'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Unsubscribed from comments',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('BlogCommentSubscriptionStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/polls' => [
                'get' => [
                    'summary' => 'List published and closed polls',
                    'description' => 'With a key, each poll also says which options the key\'s owner has voted for.',
                    'tags' => ['Polls'],
                    'responses' => [
                        '200' => [
                            'description' => 'The polls, newest first',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'polls' => ['type' => 'array', 'items' => static::schemaRef('Poll')],
                                        ],
                                        'required' => ['polls'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            '/v1/polls/{slug}' => [
                'get' => [
                    'summary' => 'Show a single poll',
                    'tags' => ['Polls'],
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
                            'description' => 'Poll details',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'poll' => static::schemaRef('Poll'),
                                        ],
                                        'required' => ['poll'],
                                    ],
                                ],
                            ],
                        ],
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/polls/{slug}/vote' => [
                'post' => [
                    'summary' => 'Vote in a poll',
                    'tags' => ['Polls'],
                    'security' => [['sanctum' => []]],
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
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['option_id'],
                                    'properties' => [
                                        'option_id' => ['type' => 'integer', 'example' => 1],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Vote recorded',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('PollVoteResult'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '404' => static::notFoundResponse(),
                        '409' => [
                            'description' => 'Already voted in this poll, or for this option',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ErrorResponse'),
                                ],
                            ],
                        ],
                        '422' => static::validationErrorResponse(),
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
            '/v1/forum/boards/{board}/threads' => [
                'post' => [
                    'summary' => 'Create a new forum thread',
                    'tags' => ['Forum Threads'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        [
                            'name' => 'board',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['title', 'body'],
                                    'properties' => [
                                        'title' => ['type' => 'string', 'maxLength' => 255],
                                        'body' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Thread created',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThreadCreationResponse'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}' => [
                'patch' => [
                    'summary' => 'Update a forum thread title',
                    'tags' => ['Forum Threads'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        [
                            'name' => 'board',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                        [
                            'name' => 'thread',
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string'],
                        ],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['title'],
                                    'properties' => [
                                        'title' => ['type' => 'string', 'maxLength' => 255],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated thread',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThread'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
                'delete' => [
                    'summary' => 'Delete a thread',
                    'tags' => ['Forum Moderation'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '204' => ['description' => 'Thread deleted'],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/posts' => [
                'post' => [
                    'summary' => 'Create a reply in a thread',
                    'tags' => ['Forum Posts'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['body'],
                                    'properties' => [
                                        'body' => ['type' => 'string', 'maxLength' => 5000],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Post created',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumPost'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/posts/{post}' => [
                'patch' => [
                    'summary' => 'Update a forum post',
                    'tags' => ['Forum Posts'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'post', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['body'],
                                    'properties' => [
                                        'body' => ['type' => 'string', 'maxLength' => 5000],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Updated post',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumPost'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/subscriptions' => [
                'post' => [
                    'summary' => 'Subscribe to a thread',
                    'tags' => ['Forum Threads'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Subscribed to thread',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumSubscriptionStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
                'delete' => [
                    'summary' => 'Unsubscribe from a thread',
                    'tags' => ['Forum Threads'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Unsubscribed from thread',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumSubscriptionStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/publish' => [
                'patch' => [
                    'summary' => 'Publish a thread',
                    'tags' => ['Forum Moderation'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Thread published',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThreadStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/unpublish' => [
                'patch' => [
                    'summary' => 'Unpublish a thread',
                    'tags' => ['Forum Moderation'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Thread unpublished',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThreadStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/lock' => [
                'patch' => [
                    'summary' => 'Lock a thread',
                    'tags' => ['Forum Moderation'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Thread locked',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThreadStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/unlock' => [
                'patch' => [
                    'summary' => 'Unlock a thread',
                    'tags' => ['Forum Moderation'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Thread unlocked',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThreadStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/pin' => [
                'patch' => [
                    'summary' => 'Pin a thread',
                    'tags' => ['Forum Moderation'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Thread pinned',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThreadStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/forum/boards/{board}/threads/{thread}/unpin' => [
                'patch' => [
                    'summary' => 'Unpin a thread',
                    'tags' => ['Forum Moderation'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'board', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'thread', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Thread unpinned',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('ForumThreadStatus'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/support/tickets' => [
                'post' => [
                    'summary' => 'Open a support ticket',
                    'tags' => ['Support'],
                    'security' => [['sanctum' => []]],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['subject', 'body'],
                                    'properties' => [
                                        'subject' => ['type' => 'string', 'maxLength' => 255],
                                        'body' => ['type' => 'string'],
                                        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                                        'support_ticket_category_id' => ['type' => 'integer', 'nullable' => true],
                                        'attachments' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string', 'format' => 'binary'],
                                            'maxItems' => 5,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Ticket created',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('SupportTicket'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/support/tickets/{ticket}' => [
                'get' => [
                    'summary' => 'Show a support ticket',
                    'tags' => ['Support'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'ticket', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Ticket details',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('SupportTicket'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/support/tickets/{ticket}/messages' => [
                'post' => [
                    'summary' => 'Post a ticket reply',
                    'tags' => ['Support'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'ticket', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['body'],
                                    'properties' => [
                                        'body' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 5000],
                                        'attachments' => [
                                            'type' => 'array',
                                            'items' => ['type' => 'string', 'format' => 'binary'],
                                            'maxItems' => 5,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '201' => [
                            'description' => 'Message created',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('SupportTicketMessageResponse'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/support/tickets/{ticket}/status' => [
                'patch' => [
                    'summary' => 'Close a ticket',
                    'tags' => ['Support'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'ticket', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['status'],
                                    'properties' => [
                                        'status' => ['type' => 'string', 'enum' => ['closed']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Ticket closed',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('SupportTicketStatusResponse'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/support/tickets/{ticket}/reopen' => [
                'patch' => [
                    'summary' => 'Reopen a closed ticket',
                    'tags' => ['Support'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'ticket', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Ticket reopened',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('SupportTicketStatusResponse'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
                    ],
                ],
            ],
            '/v1/support/attachments/{attachment}' => [
                'get' => [
                    'summary' => 'Download a support ticket attachment',
                    'description' => 'Streams the file as an attachment. Only the ticket owner and support staff may download it; the `download_url` fields on ticket messages point here.',
                    'tags' => ['Support'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'attachment', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'The attachment file',
                            'content' => [
                                'application/octet-stream' => [
                                    'schema' => ['type' => 'string', 'format' => 'binary'],
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                    ],
                ],
            ],
            '/v1/support/tickets/{ticket}/rating' => [
                'post' => [
                    'summary' => 'Submit a CSAT rating',
                    'tags' => ['Support'],
                    'security' => [['sanctum' => []]],
                    'parameters' => [
                        ['name' => 'ticket', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestBody' => [
                        'required' => true,
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'required' => ['rating'],
                                    'properties' => [
                                        'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'Rating submitted',
                            'content' => [
                                'application/json' => [
                                    'schema' => static::schemaRef('SupportTicketRatingResponse'),
                                ],
                            ],
                        ],
                        '401' => static::unauthenticatedResponse(),
                        '403' => static::forbiddenResponse(),
                        '404' => static::notFoundResponse(),
                        '422' => static::validationErrorResponse(),
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
                    'abilities' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['profile:read']],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'user' => static::schemaRef('User'),
                ],
                'required' => ['token', 'user'],
            ],
            'PollOption' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'label' => ['type' => 'string', 'example' => 'Dark mode'],
                    'votes_count' => ['type' => 'integer', 'example' => 12],
                    'vote_percent' => ['type' => 'number', 'example' => 48.0],
                ],
                'required' => ['id', 'label', 'votes_count', 'vote_percent'],
            ],
            'Poll' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 1],
                    'title' => ['type' => 'string', 'example' => 'What should we build next?'],
                    'slug' => ['type' => 'string', 'example' => 'what-should-we-build-next'],
                    'description' => ['type' => 'string', 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['published', 'closed']],
                    'allow_multiple' => ['type' => 'boolean'],
                    'starts_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'ends_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'total_votes' => ['type' => 'integer', 'example' => 25],
                    'user_vote_option_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'can_vote' => ['type' => 'boolean'],
                    'options' => ['type' => 'array', 'items' => static::schemaRef('PollOption')],
                ],
                'required' => ['id', 'title', 'slug', 'status', 'allow_multiple', 'total_votes', 'can_vote', 'options'],
            ],
            'PollVoteResult' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'example' => 'Vote recorded successfully.'],
                    'poll' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'slug' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'total_votes' => ['type' => 'integer'],
                            'options' => ['type' => 'array', 'items' => static::schemaRef('PollOption')],
                        ],
                    ],
                ],
                'required' => ['message', 'poll'],
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
            'BlogComment' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 42],
                    'body' => ['type' => 'string'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'permissions' => [
                        'type' => 'object',
                        'properties' => [
                            'can_update' => ['type' => 'boolean'],
                            'can_delete' => ['type' => 'boolean'],
                            'can_report' => ['type' => 'boolean'],
                        ],
                        'required' => ['can_update', 'can_delete', 'can_report'],
                    ],
                    'reactions' => [
                        'type' => 'object',
                        'properties' => [
                            'likes' => ['type' => 'integer'],
                            'dislikes' => ['type' => 'integer'],
                            'user_reaction' => ['type' => 'string', 'enum' => ['like', 'dislike'], 'nullable' => true],
                        ],
                        'required' => ['likes', 'dislikes', 'user_reaction'],
                    ],
                    'user' => static::schemaRef('User'),
                ],
                'required' => ['id', 'body', 'permissions', 'reactions'],
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
            'ForumPost' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'example' => 10],
                    'body' => ['type' => 'string'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'edited_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'author' => static::schemaRef('User'),
                ],
                'required' => ['id', 'body', 'author'],
            ],
            'ForumThreadCreationResponse' => [
                'type' => 'object',
                'properties' => [
                    'thread' => static::schemaRef('ForumThread'),
                    'initial_post' => [
                        'oneOf' => [
                            static::schemaRef('ForumPost'),
                            ['type' => 'null'],
                        ],
                    ],
                ],
                'required' => ['thread'],
            ],
            'ForumSubscriptionStatus' => [
                'type' => 'object',
                'properties' => [
                    'subscribed' => ['type' => 'boolean'],
                ],
                'required' => ['subscribed'],
            ],
            'BlogCommentSubscriptionStatus' => [
                'type' => 'object',
                'properties' => [
                    'subscribed' => ['type' => 'boolean'],
                    'subscribers_count' => ['type' => 'integer'],
                ],
                'required' => ['subscribed', 'subscribers_count'],
            ],
            'ForumThreadStatus' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'is_published' => ['type' => 'boolean'],
                    'is_locked' => ['type' => 'boolean'],
                    'is_pinned' => ['type' => 'boolean'],
                ],
                'required' => ['id', 'is_published', 'is_locked', 'is_pinned'],
            ],
            'SupportTicketAttachment' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'size' => ['type' => 'integer'],
                    'download_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Authenticated download link (requires the same token).'],
                ],
                'required' => ['id', 'name', 'size', 'download_url'],
            ],
            'SupportTicketMessage' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'body' => ['type' => 'string'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'author' => [
                        'oneOf' => [
                            static::schemaRef('User'),
                            ['type' => 'null'],
                        ],
                    ],
                    'is_from_support' => ['type' => 'boolean'],
                    'attachments' => [
                        'type' => 'array',
                        'items' => static::schemaRef('SupportTicketAttachment'),
                    ],
                ],
                'required' => ['id', 'body', 'created_at', 'is_from_support', 'attachments'],
            ],
            'SupportTicket' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'subject' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'example' => 'pending'],
                    'priority' => ['type' => 'string', 'example' => 'medium'],
                    'support_ticket_category_id' => ['type' => 'integer', 'nullable' => true],
                    'support_team_id' => ['type' => 'integer', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                    'customer_satisfaction_rating' => ['type' => 'integer', 'nullable' => true],
                    'assignee' => [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'nickname' => ['type' => 'string'],
                            'email' => ['type' => 'string', 'format' => 'email'],
                        ],
                    ],
                    'team' => [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                    'category' => [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                    'messages' => [
                        'type' => 'array',
                        'items' => static::schemaRef('SupportTicketMessage'),
                    ],
                ],
                'required' => [
                    'id',
                    'subject',
                    'body',
                    'status',
                    'priority',
                    'created_at',
                    'updated_at',
                    'customer_satisfaction_rating',
                    'messages',
                ],
            ],
            'SupportTicketMessageResponse' => [
                'type' => 'object',
                'properties' => [
                    'message' => static::schemaRef('SupportTicketMessage'),
                ],
                'required' => ['message'],
            ],
            'SupportTicketStatusResponse' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['status', 'message'],
            ],
            'SupportTicketRatingResponse' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string'],
                    'customer_satisfaction_rating' => ['type' => 'integer', 'nullable' => true],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['status', 'customer_satisfaction_rating', 'message'],
            ],
            'PaginatedBlogCollection' => static::paginatedSchema('Blog'),
            'PaginatedBlogCommentCollection' => static::paginatedSchema('BlogComment'),
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

    /**
     * What a key made by signing in over the API gets when the request does not say, from the site's configuration (config/api.php).
     */
    protected static function signInDescription(): string
    {
        $scopes = array_values(array_filter(array_map('strval', (array) config('api.sign_in.scopes', []))));
        $days = config('api.sign_in.expiry_days');

        return 'Signs in with an email address and password and makes an API key. Send `abilities` to choose what the key can do; without it the key gets '
            .($scopes === [] ? '`profile:read`' : implode(', ', array_map(fn (string $scope) => $scope === '*' ? 'every scope (`*`)' : "`{$scope}`", $scopes)))
            .' and lasts '.(is_numeric($days) && (int) $days > 0 ? (int) $days.' days' : 'until it is revoked (where the site allows that)')
            .'. It counts toward the limit on how many keys a person may have, is rate limited, needs a verified address where the site asks for one, and the owner is told by email.';
    }

    /**
     * Say which scope each operation needs. The scopes are read from the routes, so the documentation cannot drift from what the API
     * enforces: an operation whose route asks for one gains `x-required-scope`, a line in its description, and a 403 if it had none.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $paths
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected static function withScopes(array $paths): array
    {
        $needs = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $scopes = static::scopesOf($route);

            if ($scopes === [] || ! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                $needs[strtolower($method).' '.static::shape(substr($route->uri(), 3))] = $scopes;
            }
        }

        foreach ($paths as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $scopes = $needs[$method.' '.static::shape($path)] ?? null;

                if ($scopes === null) {
                    continue;
                }

                $names = implode(' or ', array_map(fn (string $scope) => "`{$scope}`", $scopes));

                $paths[$path][$method]['x-required-scope'] = count($scopes) === 1 ? $scopes[0] : $scopes;
                $paths[$path][$method]['description'] = trim(($operation['description'] ?? '').' Needs the '.$names.' scope.');
                $paths[$path][$method]['responses']['403'] ??= static::forbiddenResponse();
            }
        }

        return $paths;
    }

    /**
     * The scopes a route asks for with `scope:name` middleware.
     *
     * @return list<string>
     */
    protected static function scopesOf(RoutingRoute $route): array
    {
        $scopes = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'scope:')) {
                array_push($scopes, ...explode(',', substr($middleware, 6)));
            }
        }

        return $scopes;
    }

    /** An address with its parameters unnamed, so a route and a documented path can be told to be the same one. */
    protected static function shape(string $address): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $address);
    }

    protected static function forbiddenResponse(): array
    {
        return [
            'description' => 'Forbidden',
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
