<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapsuleUser extends Model
{
    protected $table = 'capsule_user';
    protected $fillable = [
        'capsule_id',
        'user_id',
        'status'
    ];

    public function capsule()
    {
        return $this->belongsTo(Capsule::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

}