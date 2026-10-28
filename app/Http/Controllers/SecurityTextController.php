<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The vulnerability contact, as RFC 9116 security.txt.
 */
class SecurityTextController extends Controller
{
    public function __invoke(): Response
    {
        $expires = now()->utc()->startOfDay()->addDays(364);
        $lines = [];

        foreach ([
            $this->field('Contact', config('security.contact.uri')),
            'Expires: '.$expires->format('Y-m-d\TH:i:s\Z'),
            $this->field('Preferred-Languages', config('security.contact.languages')),
            'Canonical: '.route('security.txt'),
            $this->field('Policy', config('security.contact.policy')),
        ] as $line) {
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    private function field(string $name, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || str_contains($value, "\n") || str_contains($value, "\r")) {
            return null;
        }

        return $name.': '.$value;
    }
}
