<?php

declare(strict_types=1);

namespace Hegel\Pest;

use Hegel\Hegel;
use Hegel\Settings;

final class SharedHegel
{
    private static ?Hegel $instance = null;
    private static bool $shutdownRegistered = false;

    public static function run(callable $property, Settings $settings, ?string $databaseKey): void
    {
        self::instance()->runWith($property, $settings, $databaseKey);
    }

    public static function shutdown(): void
    {
        self::$instance?->close();
        self::$instance = null;
    }

    private static function instance(): Hegel
    {
        if (self::$instance instanceof Hegel) {
            return self::$instance;
        }

        self::$instance = new Hegel(persistent: true);

        if (! self::$shutdownRegistered) {
            register_shutdown_function(static function (): void {
                self::shutdown();
            });
            self::$shutdownRegistered = true;
        }

        return self::$instance;
    }
}
