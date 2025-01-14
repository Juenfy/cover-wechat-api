<?php

namespace App\Services;

use App\Enums\RedisEnum;
use App\Support\Traits\ServiceException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class BaseService
{
    protected int $time;
    public function __construct()
    {
        $this->time = time();
    }

    use ServiceException;

    protected function forgetRememberCache($store, ...$keys): bool
    {
        try {
            foreach ($keys as $key) {
                Cache::store($store)->forget($key);
            }
        } catch (\Exception $e) {
            Log::channel(RedisEnum::LOG_CHANNEL)->error(__METHOD__.$e->getMessage());
            return false;
        }

        return true;
    }
}
