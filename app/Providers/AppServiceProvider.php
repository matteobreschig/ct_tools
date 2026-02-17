<?php

namespace App\Providers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('update-blueprint-price', function () {
            return Limit::perSecond(10);
        });

        Table::configureUsing(fn (Table $table) => $table->defaultDateDisplayFormat('d/m/Y')->defaultDateTimeDisplayFormat('d/m/Y H:i')->deferFilters(false));
        Schema::configureUsing(fn (Schema $schema) => $schema->defaultDateDisplayFormat('d/m/Y')->defaultDateTimeDisplayFormat('d/m/Y H:i'));
        DatePicker::configureUsing(fn (DatePicker $datePicker) => $datePicker->native(false) ->closeOnDateSelection()->defaultDateDisplayFormat('d/m/Y'));
        DateTimePicker::configureUsing(fn (DateTimePicker $dateTimePicker) => $dateTimePicker->native(false)->defaultDateTimeDisplayFormat('d/m/Y H:i'));
    }
}
