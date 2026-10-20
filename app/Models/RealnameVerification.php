<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealnameVerification extends Model
{
    protected $table = 'realname_verifications';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['verified_at' => 'immutable_datetime'];
}
