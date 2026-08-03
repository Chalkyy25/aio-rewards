<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
];
