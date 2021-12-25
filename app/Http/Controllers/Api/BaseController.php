<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BaseController extends Controller
{

    public function index()
    {
        return $this->send([
            'status' => 'online',
        ]);
    }

    protected function profileData($user)
    {
        $profileData =  $user->only('name', 'username', 'email');
        $profileData['avatar_url'] = url($user->getAvatarUrl());
        $profileData['roles'] = $user->roles()->pluck('name');
        return $profileData;
    }

    public function send($data = [], $message = 'Successful', $code = 200)
    {
        return new Response([
            'message'    => $message,
            'data'       => $data
        ], $code, [
            'Content-Type' => 'application/json'
        ]);
    }

    public function error($errors = [], $message = 'Error', $code = 400)
    {
        return new Response([
            'message'    => $message,
            'errors'     => $errors
        ], $code, [
            'Content-Type' => 'application/json'
        ]);
    }
}
