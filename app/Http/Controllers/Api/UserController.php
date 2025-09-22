<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ChangePasswordRequest;
use App\Http\Requests\Api\UpdateProfilePictureRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Notifications\PasswordChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Laravel\Facades\Image;
use Ramsey\Uuid\Uuid;

/**
 * API User Endpoints
 */
class UserController extends BaseController
{
    /**
     * User Profile
     *
     * Gets the current authenticated user profile including name, username, email and avatar url.
     *
     * @header Authorization Bearer {YOUR_AUTH_KEY}
     * @response {
     *   "message": "Successful",
     *   "data": {
     *     "id": 23,
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

    /**
     * Update User Profile
     *
     * Updates the current authenticated user profile including name, username, and email.
     *
     * @header Authorization Bearer {YOUR_AUTH_KEY}
     * @bodyParam username string The user's username
     * @bodyParam email string The user's email
     * @bodyParam name string The user's name
     * @response {
     *   "message": "Successful",
     *   "data": {
     *     "id": 23,
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
    public function updateProfile(UpdateProfileRequest $request)
    {
        $data = $request->only(['username', 'email', 'name']);
        $request->user()->update($data);

        return $this->send($this->profileData($request->user()));
    }

    /**
     * Update User Avatar
     *
     * Updates the current authenticated user's avatar.
     *
     * @header Authorization Bearer {YOUR_AUTH_KEY}
     * @bodyParam photo file The new avatar image
     * @response {
     *   "message": "Successful",
     *   "data": {
     *     "id": 23,
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
    public function updateAvatar(UpdateProfilePictureRequest $request)
    {
        $image = Image::read($request->file('photo'));

        $image->resize(256, 256, function($constraint){
            $constraint->upsize();
        });
        $image = $image->toPng();

        $filename = Uuid::uuid4()->__toString() . '.png';
        $existingFile = str_replace('/storage', '', $request->user()->getAvatarUrl());
        if(Storage::disk('public')->exists($existingFile)) {
            Storage::disk('public')->delete($existingFile);
        }
        Storage::disk('public')->put('/avatars/' . $filename, $image);
        $request->user()->update(['avatar_url' => '/storage/avatars/' . $filename]);

        return $this->send($this->profileData($request->user()));
    }

    /**
     * Change User Password
     *
     * Changes the current authenticated user's password.
     *
     * @header Authorization Bearer {YOUR_AUTH_KEY}
     * @bodyParam existing_password string The existing password
     * @bodyParam password string The new password
     * @bodyParam password_confirmation string The new password again
     * @response {
     *   "message": "Successful",
     *   "data": {
     *     "id": 23,
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
    public function changePassword(ChangePasswordRequest $request)
    {
        $data = $request->only(['existing_password', 'password']);
        if(!Hash::check($data['existing_password'], $request->user()->password)) {
            return $this->error([
                'existing_password' => ['Existing password is invalid'],
            ]);
        }

        $user = $request->user();
        $user->password = bcrypt($data['password']);
        $user->save();

        $user->notify(new PasswordChangedNotification());

        return $this->send($this->profileData($request->user()));
    }
}
