<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
     public $timestamps = false;
    use HasApiTokens, HasFactory, Notifiable;
   
    // ✅ cho phép insert
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    // ✅ ẩn khi trả JSON
    protected $hidden = [
        'password',
        'remember_token',
    ];

    // ✅ cast dữ liệu
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}
