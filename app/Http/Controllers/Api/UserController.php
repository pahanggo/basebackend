<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API User Endpoints
 */
class UserController extends BaseController
{
    /**
     * User Profile
     *
     * Get's the current authenticated user profile including name, username, email and avatar url.
     *
     * @header Authorization Bearer {YOUR_AUTH_KEY}
     * @response {
     *   "message": "Successful",
     *   "data": {
     *     "name": "User",
     *     "username": "User",
     *     "email": "user@example.com",
     *     "avatar_url": "https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&r=g",
     *     "roles": [
     *       "User"
     *     ]
     *   }
     * }
     */
    public function profile(Request $request)
    {
        return $this->send($this->profileData($request->user()));
    }
}
