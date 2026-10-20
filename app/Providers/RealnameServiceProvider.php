<?php

namespace App\Providers;

use App\Services\Realname\AlipayIdentityProvider;
use App\Services\Realname\IdentityProvider;
use Illuminate\Support\ServiceProvider;

class RealnameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdentityProvider::class, AlipayIdentityProvider::class);
    }
}
