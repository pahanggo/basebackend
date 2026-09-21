<?php

namespace Backpack\CRUD\app\Http\Controllers\Auth;

use Backpack\CRUD\app\Library\Auth\ResetsPasswords;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;

class ResetPasswordController extends Controller
{
    protected $data = []; // the information we send to the view

    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Get the path the user should be redirected to after password reset.
     *
     * @param  Request  $request
     * @return string
     */
    public function redirectTo()
    {
        return backpack_url();
    }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $guard = backpack_guard_name();

        $this->middleware("guest:$guard");

        if (! backpack_users_have_email()) {
            abort(501, trans('backpack::base.no_email_column'));
        }

        // where to redirect after password was reset
        $this->redirectTo = property_exists($this, 'redirectTo') ? $this->redirectTo : backpack_url('dashboard');
    }

    // -------------------------------------------------------
    // Laravel overwrites for loading backpack views
    // -------------------------------------------------------

    /**
     * Display the password reset view for the given token.
     *
     * If no token is present, display the link request form.
     *
     * @param  string|null  $token
     * @return Response
     */
    public function showResetForm(Request $request, $token = null)
    {
        $this->data['title'] = trans('backpack::base.reset_password'); // set the page title

        return view(backpack_view('auth.passwords.reset'), $this->data)->with(
            ['token' => $token, 'email' => $request->email]
        );
    }

    /**
     * Get the broker to be used during password reset.
     *
     * @return PasswordBroker
     */
    public function broker()
    {
        $passwords = config('backpack.base.passwords', config('auth.defaults.passwords'));

        return Password::broker($passwords);
    }

    /**
     * Get the guard to be used during password reset.
     *
     * @return StatefulGuard
     */
    protected function guard()
    {
        return backpack_auth();
    }
}
