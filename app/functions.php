<?php

// @caoxl

if (! function_exists('fe')) {
    function fe($name) {
        if (is_string($name) && $name) {
            return function_exists($name);
        }

        return false;
    }
}
if (! fe('cfg')) {
    function cfg($key) {
        if (!is_string($key) || empty($key)) {
            return null;
        }

        if (false
            || (! isset($GLOBALS['__CONFIG']))
            || (! empty($GLOBALS['__CONFIG']))
            || (! is_array($GLOBALS['__CONFIG']))
        ) {
            $path = config_path('config.json');
            if (true
                && file_exists($path)
                && ($config = trim(file_get_contents($path)))
                && ($config = json_decode($config, true))
            ) {
                $GLOBALS['__CONFIG'] = $config;
            } else {
                return null;
            }
        }

        return isset($GLOBALS['__CONFIG'][$key])
            ? $GLOBALS['__CONFIG'][$key]
            : null;
    }
}
if (! fe('gfc')) {
    function gfc($data) {
        if (!is_array($data) || empty($data)) {
            return null;
        }

        $path = config_path('config.json');
        $old  = [];

        if (file_exists($path)) {
            $old = (array) json_decode(file_get_contents($path), true);
        } else {
            if (! touch($path)) {
                return null;
            }
        }

        foreach ($data as $key => $value) {
            $old[$key] = $value;
        }

        if ($new = json_encode(
            $old,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )) {
            file_put_contents($path, $new);
        }
    }
}
if ( ! fe('config_path')) {
    function config_path(string $fname = null) {
        return app()->basePath('config').($fname ? "/{$fname}" : '');
    }
}