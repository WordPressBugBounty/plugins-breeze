<?php

declare (strict_types=1);
namespace Breeze\Detection\Cache;

use Breeze\Psr\SimpleCache\InvalidArgumentException;
class CacheInvalidArgumentException extends CacheException implements InvalidArgumentException
{
}
