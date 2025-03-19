<?php

namespace MixCode\FilamentMulti2fa\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \MixCode\FilamentMulti2fa\FilamentMulti2fa
 */
class FilamentMulti2fa extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \MixCode\FilamentMulti2fa\FilamentMulti2fa::class;
    }
}
