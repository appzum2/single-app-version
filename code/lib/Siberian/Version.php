<?php

class Siberian_Version
{
    const TYPE = 'SAE';
    const NAME = 'Single App Edition';
    const VERSION = '4.8.11.2';
    const NATIVE_VERSION = '2';

    static function is($type) {
        return self::TYPE == strtoupper($type);
    }
}
