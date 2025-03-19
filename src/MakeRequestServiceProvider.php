<?php

namespace desole\MakeRequest;

use Illuminate\Support\ServiceProvider;
use desole\MakeRequest\Commands\MakeRequest;

class MakeRequestServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            MakeRequest::class,
        ]);
    }


}