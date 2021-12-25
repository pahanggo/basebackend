<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        return view('welcome');
    }

    public function verifyEmail($id, $hash)
    {
        $user = User::find($id) ?? new User();
        $equals = hash_equals($hash, sha1($user->getEmailForVerification()));
        if($user->id && $equals && !$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
        return view('verified');
    }

    public function passwordChanged()
    {
        return view('password-changed');
    }
}
