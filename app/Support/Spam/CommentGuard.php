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
        // This runs before the form is validated, so either field may not be text (honeypot[]=x). Nobody fills the trap in with
        // anything but text, so anything else counts as filled in, and a token that is not text is not the token.
        $honeypot = $request->input('honeypot');

        if ($honeypot !== null && (! is_string($honeypot) || trim($honeypot) !== '')) {
            throw ValidationException::withMessages([
                'honeypot' => 'Spam check failed.',
            ]);
        }

        $session = $request->hasSession() ? $request->session() : null;

        if ($session === null) {
            return;
        }

        $token = $request->input('captcha_token');
        $expected = $session->get(self::SESSION_TOKEN_KEY, '');

        if (! is_string($expected) || $expected === '' || ! is_string($token) || ! hash_equals($expected, $token)) {
            throw ValidationException::withMessages([
                'captcha_token' => 'Invalid verification token.',
            ]);
        }
    }
}
