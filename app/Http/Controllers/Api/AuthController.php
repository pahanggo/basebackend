<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Models\User;
use Backpack\CRUD\app\Library\Auth\PasswordBrokerManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * API Authentication Endpoints
 *
 * @package App\Http\Controllers\Api
 */
class AuthController extends BaseController
{
    /**
     * Login
     *
     * Checks the supplied username and password and returns a token if the details are valid
     *
     * @unauthenticated
     * @bodyParam username string The user's name
     * @bodyParam password string The user's password
     * @response {
     *   "message": "Successful",
     *   "data": {
     *     "user": {
     *       "id": 23,
     *       "name": "User",
     *       "username": "User",
     *       "email": "user@example.com",
     *       "avatar_url": "https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&r=g",
     *       "roles": [
     *         "User"
     *       ]
     *     },
     *     "access_token": "4|XP9j2cxsNudDXf2JxPIMhe0XCNHIPzEPIMYC7RuL"
     *   }
     * }
     * @response status=400 scenario="Invalid username or password" {
     *  "message": "Invalid Username or Password"
     * }
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->only('username', 'password');
        $user = User::whereUsername($credentials['username'])->first();
        if(!$user) {
            return $this->error([], 'Invalid Username or Password', 400);
        }

        if(!Hash::check($credentials['password'], $user->password)) {
            return $this->error([], 'Invalid Username or Password', 400);
        }

        $token = $user->createToken('api')->plainTextToken;
        return $this->send([
            'user'         => $this->profileData($user),
            'access_token' => $token
        ]);
    }

    /**
     * Register
     *
     * Registeres a new user.
     *
     * @unauthenticated
     * @bodyParam name string The new user's name
     * @bodyParam username string The new user's username
     * @bodyParam email string The new user's email
     * @bodyParam password string The password
     * @bodyParam password_confirmation string The password again
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
    public function register(RegisterRequest $request)
    {
        $params = $request->only([
            'name',
            'username',
            'email',
            'password'
        ]);

        $params['password'] = bcrypt($params['password']);

        $user = User::create($params);

        $user->sendEmailVerificationNotification();

        $user->assignRole('User');

        return $this->send();
    }

    /**
     * Forgot Password
     *
     * Requests a Reset Password Email.
     *
     * @unauthenticated
     * @bodyParam username string The user's username or email
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
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $user = User::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        if($user) {
            $this->broker()->sendResetLink(['email' => $user->email]);
        }

        return $this->send();
    }

    protected function broker()
    {
        $passwords = config('backpack.base.passwords', config('auth.defaults.passwords'));
        $manager = new PasswordBrokerManager(app());

        return $manager->broker($passwords);
    }

    public function logout()
    {
        return $this->send();
    }
}
