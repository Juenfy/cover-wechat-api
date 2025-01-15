<?php

namespace App\Models;

class RedPacket extends Base
{
    public $timestamps = false;

    public function from(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user', 'id');
    }
}
