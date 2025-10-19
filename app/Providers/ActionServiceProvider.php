<?php

namespace App\Providers;

use App\Actions\FinalizeImport;
use App\Actions\ImportItemDetails;
use App\Actions\ImportItems;
use Illuminate\Support\ServiceProvider;
use Lorisleiva\Actions\Facades\Actions;

class ActionServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register actions by scanning the Actions directory
        Actions::registerCommands(app_path('Actions'));
    }
}
