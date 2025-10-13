<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserSummaryResource;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function __invoke(Request $request): UserSummaryResource
    {
        return new UserSummaryResource($request->user());
    }
}
