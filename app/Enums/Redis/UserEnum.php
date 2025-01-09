<?php

namespace App\Enums\Redis;

class UserEnum
{
    const STORE = 'user';
    const JOIN_GROUPS = 'join_groups:%s';
    const BIND_UID = 'bind_uid:%s';
    const RED_PACKET = 'red_packet:%s';
    const RED_PACKET_RECEIVE_LOCK = 'red_packet_receive_lock:%s';
}
