<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function capsules()
    {
        return $this->hasMany(Capsule::class);
    }

    public function sentFriendRequests()
    {
        return $this->hasMany(FriendRequest::class, 'user_id');
    }

    public function receivedFriendRequests()
    {
        return $this->hasMany(FriendRequest::class, 'friend_id');
    }

    public function friends()
    {
        return $this->belongsToMany(User::class, 'friendships', 'user_id', 'friend_id')
            ->wherePivot('status', 'accepted')
            ->union(
                $this->belongsToMany(User::class, 'friendships', 'friend_id', 'user_id')
                    ->wherePivot('status', 'accepted')
            );
    }
}