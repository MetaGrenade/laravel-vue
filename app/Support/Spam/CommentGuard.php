<?php

namespace App\Support\Spam;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommentGuard
{
    public const SESSION_TOKEN_KEY = 'blog_comment_captcha_token';

    public function issueToken(Request $request): string
    {
        $session = $request->hasSession() ? $request->session() : null;

        if ($session === null) {
            return Str::random(40);
        }

        $token = $session->get(self::SESSION_TOKEN_KEY);

        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
        }

        $session->put(self::SESSION_TOKEN_KEY, $token);

        return $token;
    }

    public function validate(Request $request): void
    {
        $honeypot = trim((string) $request->input('honeypot', ''));

        if ($honeypot !== '') {
            throw ValidationException::withMessages([
                'honeypot' => 'Spam check failed.',
            ]);
        }

        $session = $request->hasSession() ? $request->session() : null;

        if ($session === null) {
            return;
        }

        $token = (string) $request->input('captcha_token', '');
        $expected = $session->get(self::SESSION_TOKEN_KEY, '');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $token)) {
            throw ValidationException::withMessages([
                'captcha_token' => 'Invalid verification token.',
            ]);
        }
    }
}
