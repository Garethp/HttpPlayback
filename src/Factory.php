<?php

namespace garethp\HttpPlayback;

class Factory
{
    private static $instances = [];

    public static function getInstance($options = [])
    {
        foreach (self::$instances as $instance) {
            if ($instance['options'] == $options) {
                return $instance['instance'];
            }
        }

        $instance = new Client($options);
        self::$instances[] = [
            'instance' => $instance,
            'options' => $options
        ];

        return $instance;
    }
}
