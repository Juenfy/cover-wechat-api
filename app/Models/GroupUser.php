<?php

namespace App\Models;

class GroupUser extends Base
{
    protected $casts = [
        'setting' => 'json'
    ];

    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id', 'id');
    }


    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 是否群成员
     * @param $userId
     * @param $groupId
     * @param bool $returnGroup
     * @return bool|array
     */
    public static function checkIsGroupMember($userId, $groupId, bool $returnGroup = false): bool|array
    {
        $group = self::query()
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->first(['group_id', 'user_id', 'nickname']);
        $group = $group ? $group->toArray() : [];
        return $returnGroup ? [(bool)$group, $group] : (bool)$group;
    }

    public function getBgFilePathAttribute($value): string
    {
        if (empty($value)) return '';
        if (!str_contains($value, 'http')) return env('STATIC_FILE_URL') . $value;
        return $value;
    }
}
