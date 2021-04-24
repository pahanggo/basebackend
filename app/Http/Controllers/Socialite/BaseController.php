<?php

namespace App\Http\Controllers\Socialite;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Prologue\Alerts\Facades\Alert;

class BaseController extends Controller
{
    public function redirectToProvider()
    {
        return Socialite::driver($this->driver)->redirect();
    }

    public function handleProviderCallback()
    {
        try {
            $user = Socialite::driver($this->driver)->user();
        } catch (\Exception $e) {
            Alert::error('Invalid social login')->flash();
            return redirect(route('backpack.auth.login'));
        }

        $socialAccount = SocialAccount::handleCallback($this->driver, $user);

        $systemUser = $socialAccount->user;

        // 1) system user exists
        if($systemUser) {
            Auth::login($systemUser, true);
            Alert::success('Welcome ' . $systemUser->name)->flash();
            return redirect()->intended(backpack_url('dashboard'));
        }

        $loggedInUser = user();
        if($loggedInUser) {
            // 2) link to logged in user account
            // prompt for password
            $socialAccount->user_id = $loggedInUser->id;
            $socialAccount->save();
            Alert::success('Linked social account.')->flash();
            return redirect()->route('backpack.account.info');
        } else if(User::where('email', $user->email)->first()) {
            // 3) email exists in the system and no login session
            Alert::error('A user already registered with this email. Kindly use the Social Accounts links in your profile.')->flash();
            return redirect(route('backpack.auth.login'));
        } else if(config('backpack.base.registration_open')) {
            // 3) email is not in system
            // create new user
            return view('socialite.register', [
                'socialite_token' => encrypt($socialAccount->id),
                'socialite' => $socialAccount,
            ]);
        }

        Alert::error('User does not exist.')->flash();
        return redirect(route('backpack.auth.login'));
    }

    public function unlink(Request $request)
    {
        $token = $request->get('token');
        if($token) {
            user()->socialAccounts()->whereId(decrypt($token))->update(['user_id' => null]);
        }

        Alert::success('Unlinked social account.')->flash();
        return redirect()->back();
    }
}
