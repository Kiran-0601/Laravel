<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Auth\Notifications\ResetPassword;



class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'lname',
        'country',
        'mobile',
        'dob',
        'gender',
        'address',
        'email',
        'password',
        'user_type',
        'status',
        'image',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function sendPasswordResetNotification($token)
    {
        $url = $this->resetUrl($token);
        $notification = new ResetPassword($token);
        $notification->createUrlUsing(function ($notifiable, $token) use ($url) {
            return $url;
        });

        $this->notify($notification);
    }
    protected function resetUrl($token)
    {
        if ($this->user_type == 1) {
            return url(route('admin.password.reset', [
                'token' => $token,
                'email' => $this->email,
            ], false));
        } else {
            // For other user types, use the default behavior
            return url(route('password.reset', [
                'token' => $token,
                'email' => $this->email,
            ], false));
        }
    }
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }
}
