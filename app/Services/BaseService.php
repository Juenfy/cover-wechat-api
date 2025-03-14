<?php

namespace App\Services;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\MessageEnum;
use App\Enums\RedisEnum;
use App\Exceptions\BusinessException;
use App\Models\Friend;
use App\Models\GroupUser;
use App\Support\Traits\ServiceException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

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
            Log::channel(RedisEnum::LOG_CHANNEL)->error(__METHOD__ . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * 清除记录
     * @param $fromUser
     * @param $toUser
     * @param $isGroup
     * @param $type chat=聊天列表 message=聊天记录
     * @return void
     * @throws BusinessException
     */
    protected function clearRecord($fromUser, $toUser, $isGroup, string $type = 'chat'): void
    {
        DB::beginTransaction();
        try {
            $update = $type === 'chat' ? ['display' => 0, 'content' => ''] : ['content' => ''];
            if ($isGroup == MessageEnum::GROUP) {
                DB::update("UPDATE cw_messages SET deleted_users=TRIM(BOTH ',' FROM CONCAT(deleted_users, ',', {$fromUser})) WHERE (from_user={$fromUser} AND to_user={$toUser}) AND is_group={$isGroup} AND (FIND_IN_SET('{$fromUser}', deleted_users) = '')");
                GroupUser::query()
                    ->where('group_id', $toUser)
                    ->where('user_id', $fromUser)
                    ->update($update);


            } else {
                DB::update("UPDATE cw_messages SET deleted_users=TRIM(BOTH ',' FROM CONCAT(deleted_users, ',', {$fromUser})) WHERE ((from_user={$fromUser} AND to_user={$toUser}) OR (from_user={$toUser} AND to_user={$fromUser})) AND is_group={$isGroup} AND (FIND_IN_SET('{$fromUser}', deleted_users) = '')");
                Friend::query()
                    ->where('owner', $fromUser)
                    ->where('friend', $toUser)
                    ->update($update);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }
}
