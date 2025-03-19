<?php

namespace desole\MakeRequest;

use Illuminate\Support\ServiceProvider;
use YourVendor\MakeRequest\Commands\MakeRequest;
class MakeRequestServiceProvider
{
    public function register()
    {
        $this->commands([
            MakeRequest::class,
        ]);
    }

    public function boot()
    {
        // Публикация конфигураций, миграций и т.д. (опционально)
    }
}