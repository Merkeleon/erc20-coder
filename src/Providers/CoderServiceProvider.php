<?php

namespace Merkeleon\Coder\Providers;

use Illuminate\Support\ServiceProvider;

class CoderServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/config/abi.php', 'abi'
        );
    }
}
