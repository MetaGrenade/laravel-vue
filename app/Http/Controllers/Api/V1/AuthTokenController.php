<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTokenRequest;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthTokenController extends Controller
{
    public function store(StoreTokenRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->userFromCredentials();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $token = $user->createToken(
            $data['device_name'] ?? 'api',
            $data['abilities'] ?? ['*']
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => optional($token->accessToken)->expires_at,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        $key = $request->user()?->currentAccessToken();

        // Revoked, not deleted: the request is still logged against the key once this returns, and a key that is gone has no row to log
        // to. It stays in Settings → API keys as revoked, where it can be removed.
        if ($key instanceof PersonalAccessToken && ! $key->isRevoked()) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return response()->noContent();
    }
}
