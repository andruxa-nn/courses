<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'displayErrorDetails' => false, // Should be set to false in production
            'logger' => [
                'name' => 'slim-app',
                'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
                'level' => Logger::DEBUG,
            ],
            'cachePath' => __DIR__ . '/../cache',
            'cacheTime' => 60,
            'httpClient' => [
                'timeout' => 3.14
            ],
            'coursesUrl' => 'https://www.cbr-xml-daily.ru/daily_json.js'
        ],
    ]);
};
