<?php

namespace App\Enums\Database;

class RedPacketEnum
{
    const TYPE_NORMAL = 'normal';
    const TYPE_LUCKY = 'lucky';
    const TYPE_BELONG = 'belong';
    const TYPE = [
        self::TYPE_NORMAL,
        self::TYPE_LUCKY,
        self::TYPE_BELONG
    ];
}
