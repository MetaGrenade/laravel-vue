<?php

namespace Spatie\Activitylog;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class ActivitylogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/activitylog.php', 'activitylog');

        $this->app->singleton(ActivityLogStatus::class, function (Application $app) {
            $config = $app->make(ConfigRepository::class);

            return new ActivityLogStatus((bool) $config->get('activitylog.enabled', true));
        });

        $this->app->bind(ActivityLogger::class, function (Application $app) {
            $request = $app->bound('request') ? $app->make('request') : null;

            return new ActivityLogger(
                $app->make(ActivityLogStatus::class),
                $request,
                $app['config']->get('activitylog.default_log_name', 'default'),
            );
        });

        $this->app->alias(ActivityLogger::class, 'activitylog');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/activitylog.php' => config_path('activitylog.php'),
            ], 'activitylog-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_activity_log_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time()).'_create_activity_log_table.php'),
            ], 'activitylog-migrations');
        }

        Activity::resolveRelationUsing('causer', function (Activity $activity) {
            return $activity->morphTo();
        });

        Activity::resolveRelationUsing('subject', function (Activity $activity) {
            return $activity->morphTo();
        });
    }
}
