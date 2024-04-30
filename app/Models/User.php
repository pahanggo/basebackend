<?php

namespace App\Models;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Creativeorange\Gravatar\Facades\Gravatar;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, CrudTrait, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'avatar_url',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function getWidgetsAttribute($value)
    {
        return json_decode($value, true) ?? [['welcome']];
    }

    public function setWidgetsAttribute($value)
    {
        $this->attributes['widgets'] = json_encode($value);
    }

    public function getAvatarUrl()
    {
        if($this->avatar_url) {
            return $this->avatar_url;
        }
        return \Avatar::create($this->name)->toBase64();
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function socialiteLinked($type)
    {
        return $this->socialAccounts()->whereAccountType($type)->exists();
    }
}
