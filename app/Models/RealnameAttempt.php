<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RealnameAttempt extends Model
{
    protected $table = 'realname_attempts';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['certify_id', 'certify_id_hmac', 'auth_url', 'idempotency_hmac', 'request_hmac'];
    protected $casts = [
        'certify_id' => 'encrypted',
        'auth_url' => 'encrypted',
        'expires_at' => 'immutable_datetime',
        'next_query_at' => 'immutable_datetime',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, ['initializing', 'pending', 'uncertain'], true);
    }
}
