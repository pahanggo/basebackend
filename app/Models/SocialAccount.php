<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialAccount extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public $dates = [
        'token_expires_at',
        'last_login_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function handleCallback($driver, SocialiteUser $user) : SocialAccount
    {
        // Laravel\Socialite\Two\User {#610 ▼
        //     +token: "ya29.a0AfH6SMDDm-Sd_Tn8l90MPkF8OEtO0dOZHmo7z3RfejuRM7lJZky29DuDXWfynB5YM16-9IPZ3htLfi3VRN4A1Py2ffAahmMrC5A5vInJu3g5cGoeIGxDQCF0F6fvTY0bTCikqbXkpwUcc-DkqKtBy7wi8 ▶"
        //     +refreshToken: null
        //     +expiresIn: 3599
        //     +id: "116077240793134603506"
        //     +nickname: null
        //     +name: "Zulfa Juniadi Zulkifli"
        //     +email: "zulfajuniadi@gmail.com"
        //     +avatar: "https://lh3.googleusercontent.com/a-/AOh14Gi45ZNwZTZj2_GU5-VPXoc25Hr3FJpdxAGdrT-qQw=s96-c"
        //     +user: array:11 [▶]
        //     +"avatar_original": "https://lh3.googleusercontent.com/a-/AOh14Gi45ZNwZTZj2_GU5-VPXoc25Hr3FJpdxAGdrT-qQw=s96-c"
        //   }

        return SocialAccount::updateOrCreate([
            'social_user_id' => $user->id,
            'account_type' => $driver,
        ], [
            'name' => $user->name ?? '',
            'email' => $user->email ?? '',
            'avatar' => $user->avatar ?? '',
            'avatar_original' => $user->avatar_original ?? '',
            'token' => $user->token,
            'token_expires_at' => now()->addSeconds($user->expiresIn),
            'last_login_at' => now(),
        ]);
    }
}
