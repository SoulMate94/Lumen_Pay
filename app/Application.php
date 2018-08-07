<?php

// Custom default or hard-coded behavior of lumen
// @caoxl

namespace App;

use Laravel\Lumen\Application as LumenBase;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Application extends LumenBase
{
    // Rewrite log handler
    protected function getMonologHandler()
    {
        return (
        new StreamHandler(storage_path(env(
            'APP_LOG_PATH',
            'logs/'.date('Y-m-d').'.log'
        )),
            Logger::DEBUG
        ))
            ->setFormatter(new LineFormatter(null, null, true, true));
    }
}
