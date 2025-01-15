<?php

namespace App\Models;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\UserEnum;
use App\Exceptions\BusinessException;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Lumen\Auth\Authorizable;

class User extends Base implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable, Authorizable, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = ['avatar', 'nickname', 'gender', 'setting', 'unread'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var string[]
     */
    protected $hidden = [
        'password',
        'salt'
    ];

    protected $casts = [
        'setting' => 'json',
        'unread' => 'json'
    ];

    /**
     * 头像路径处理
     *
     * @param string $value
     * @return string
     */
    public function getAvatarAttribute($value)
    {
        if (!str_contains($value, 'http')) return env('STATIC_FILE_URL') . $value;
        return $value;
    }

    public function getBgFilePathAttribute($value): string
    {
        if (empty($value)) return '';
        if (!str_contains($value, 'http')) return env('STATIC_FILE_URL') . $value;
        return $value;
    }

    public function getMomentBgFilePathAttribute($value): string
    {
        if (empty($value)) return '';
        if (!str_contains($value, 'http')) return env('STATIC_FILE_URL') . $value;
        return $value;
    }

    /**
     * 获取消息未读数
     * @param int $id
     * @return array
     */
    public static function getUnreadById(int $id): array
    {
        $user = self::query()->find($id, ['unread']);
        return $user->unread;
    }

    /**
     * 增加信息未读数
     * @param array $ids
     * @param string $field
     * @param int $from
     * @param $num
     * @return int
     */
    public static function incrUnread(array $ids, string $field, int $from = 0, $num = 1): int
    {
        if (empty($ids)) return 0;
        if ($field == 'moment.num') {
            self::query()->whereIn('id', $ids)->update([
                'unread' => DB::raw("JSON_SET(unread, '$.moment.from', {$from})")
            ]);
        }
        return self::query()->whereIn('id', $ids)->update([
            'unread' => DB::raw("JSON_SET(unread, '$.{$field}', JSON_EXTRACT(unread, '$.{$field}') + {$num})")
        ]);
    }

    /**
     * 清空消息未读数
     * @param array $ids
     * @param string $field
     * @return int
     */
    public static function clearUnread(array $ids, string $field): int
    {
        return self::query()->whereIn('id', $ids)->update([
            'unread' => DB::raw("JSON_SET(unread, '$.{$field}', 0)")
        ]);
    }

    public static function checkExistsBySetting(int $userId, string $column, string $value): bool
    {
        return self::query()->where('id', $userId)->whereRaw("JSON_EXTRACT(setting, '$.{$column}') = '{$value}'")->exists();
    }

    /**
     * 充值
     * @param int|object $userId
     * @param string $money
     * @param string $changeType
     * @param array $extend
     * @return int
     * @throws BusinessException
     */
    public static function changeMoney(int|object $userId, string $money, string $changeType = UserEnum::MONEY_INCR, array $extend = []): int
    {
        if (is_int($userId))
            $user = self::query()->findOrFail($userId);
        else
            $user = $userId;
        $money = $money * 100;
        $moneyBefore = $user->money;
        if ($changeType === UserEnum::MONEY_INCR)
            $user->money = $user->money + $money;
        elseif ($changeType === UserEnum::MONEY_DECR) {
            if ($money > $user->money)
                throw new BusinessException(ApiCodeEnum::SERVICE_ACCOUNT_MONEY_NOT_ENOUGH);
            $user->money = $user->money - $money;
        }
        $moneyAfter = $user->money;
        if ($user->save() && isset($extend['money_flow_type'])) {
            $data = [
                'type' => $extend['money_flow_type'],
                'change_type' => $changeType,
                'from_id' => $extend['from_id'] ?? 0,
                'user_id' => $user->id,
                'money_before' => $moneyBefore,
                'money_after' => $moneyAfter,
                'money' => $money,
                'remark' => $extend['remark'] ?? '',
                'created_at' => time()
            ];
            MoneyFlowLog::query()->insert($data);
        }
        return $user->money;
    }
}
