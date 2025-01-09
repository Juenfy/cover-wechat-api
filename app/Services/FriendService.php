<?php

namespace App\Services;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\FriendEnum;
use App\Enums\Database\MessageEnum;
use App\Enums\Redis\FriendEnum as RedisFriendEnum;
use App\Enums\WorkerManEnum;
use App\Exceptions\BusinessException;
use App\Models\Friend;
use App\Models\Message;
use App\Models\User;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FriendService extends BaseService
{
    /**
     * 好友列表
     * @param array $params
     * @return array
     */
    public function list(array $params): array
    {
        $userId = $params['user']->id;
        $friendList = Friend::query()->with(['friend' => function ($query) {
            $query->select(['id', 'nickname', 'avatar', 'wechat', 'mobile']);
        }])->where('owner', $userId)
            ->where('status', FriendEnum::STATUS_PASS)
            ->when(!empty($params['type']) && $params['type'] == 'only_chat', function ($query) {
                $query->whereJsonContains('setting', ["FriendPerm" => ["SettingFriendPerm" => 'ONLY_CHAT']]);
            })
            ->get(['id', 'owner', 'friend', 'nickname', 'source', 'desc'])->toArray();

        foreach ($friendList as &$friend) {
            $friend['nickname'] = $friend['nickname'] ?: $friend['friend']['nickname'];
            $friend['avatar'] = $friend['friend']['avatar'];
            $friend['keywords'] = $friend['friend'][$friend['source']] ?? $friend['friend']['wechat'];
            $friend['checked'] = false;
            $friend['friend'] = $friend['friend']['id'];
        }
        unset($friend);
        return group_by_first_char($friendList, 'nickname');
    }

    /**
     * 好友申请列表
     * @param array $params
     * @return array
     */
    public function applyList(array $params): array
    {
        $userId = $params['user']->id;
        $applyList = Friend::query()->with(['friend' => function ($query) {
            $query->select(['id', 'nickname', 'avatar', 'mobile', 'wechat']);
        }, 'owner' => function ($query) {
            $query->select(['id', 'nickname', 'avatar', 'mobile', 'wechat']);
        }])
            ->where('hide', 0)
            ->whereRaw("(owner = {$userId} OR friend = {$userId}) and remark <> ''")
            ->get()->toArray();

        $day = 86400;
        $threeDay = $overThreeDay = [];
        foreach ($applyList as &$apply) {
            if ($apply['friend']['id'] == $userId) {
                $owner = $apply['owner'];
                $friend = $apply['friend'];
                $apply['owner'] = $friend;
                $apply['friend'] = $owner;
            }
            if ($apply['type'] == FriendEnum::TYPE_APPLY) {
                $apply['status'] = $apply['friend']['id'] == $userId ? 'go_check' : 'wait_check';
            }
            $apply['keywords'] = $apply['friend'][$apply['source']] ?? $apply['friend']['wechat'];
            unset($apply['friend']['mobile'], $apply['friend']['wechat'], $apply['owner']['mobile'], $apply['owner']['wechat']);
            $days = (time() - strtotime($apply['created_at'])) / $day;
            if ($days > 3) {
                $overThreeDay[] = $apply;
            } else {
                $threeDay[] = $apply;
            }
        }
        User::clearUnread([$userId], 'apply');
        return ['three_day' => $threeDay, 'over_three_day' => $overThreeDay];
    }

    /**
     * 删除好友申请
     * @param int $id
     * @param int $userId
     * @return int
     */
    public function deleteApply(int $id, int $userId): int
    {
//        $delKeys = [
//            sprintf(RedisFriendEnum::APPLY_LIST, $userId)
//        ];
//        $this->forgetRememberCache(RedisFriendEnum::STORE, ...$delKeys);
        return Friend::query()->where('id', $id)->update(['hide' => 1]);
    }

    /**
     * 查找好友
     * @param array $params
     * @return array
     */
    public function search(array $params): array
    {
        $keywords = $params['keywords'];
        //黑名单
        $isMobile = is_mobile($keywords);
        $source = $isMobile ? FriendEnum::SOURCE_MOBILE : FriendEnum::SOURCE_WECHAT;
        $friend = User::query()->where($source, $keywords)->whereJsonContains('setting', ["FriendPerm" => ["AddMyWay" => [ucfirst($source) => "1"]]])->get(['id', 'nickname', 'avatar']);
        if ($friend) {
            $friend = $friend->toArray();
            foreach ($friend as &$v) {
                $v['keywords'] = $keywords;
            }
        }
        return $friend ?: [];
    }

    /**
     * 好友验证信息
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function showConfirm(array $params): array
    {
        $confirm = [];
        $source = $params['source'];
        $relationship = $params['relationship'];
        if ($relationship == 'friend') {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }
        if (!in_array($source, [FriendEnum::SOURCE_WECHAT, FriendEnum::SOURCE_MOBILE])) {
            $source = FriendEnum::SOURCE_WECHAT;
        }
        $user = User::query()->where($source, $params['keywords'])->whereJsonContains('setting', ["FriendPerm" => ["AddMyWay" => [ucfirst($source) => "1"]]])->first(['id', 'nickname']);
        if (empty($user)) $this->throwBusinessException(ApiCodeEnum::SERVICE_ACCOUNT_NOT_FOUND);
        $confirm['friend'] = $user->id;
        $confirm['nickname'] = $user->nickname;
        $confirm['setting'] = config('user.friend.setting');
        if ($relationship !== 'go_check') {
            $confirm['type'] = FriendEnum::TYPE_APPLY;
            $confirm['remark'] = "我是{$params['user']['nickname']}";
        } else {
            $confirm['type'] = FriendEnum::TYPE_VERIFY;
            $confirm['remark'] = '';
        }
        return $confirm;
    }

    /**
     * 申请添加好友
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function apply(array $params): array
    {
        //黑名单
        $keywords = $params['keywords'];
        $isMobile = is_mobile($keywords);
        $source = $isMobile ? FriendEnum::SOURCE_MOBILE : FriendEnum::SOURCE_WECHAT;
        $params['friend'] = User::query()->where($source, $keywords)->whereJsonContains('setting', ["FriendPerm" => ["AddMyWay" => [ucfirst($source) => "1"]]])->value('id');
        if (!$params['friend']) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        $friend = Friend::query()->where('owner', $params['user']->id)->where('friend', $params['friend'])->first();
        $owner = Friend::query()->where('owner', $params['friend'])->where('friend', $params['user']->id)->first();
        DB::beginTransaction();
        try {
            if ($friend) {
                //已经申请过了
                //双方已是好友
                if (!$friend->deleted_at && ($owner && !$owner->deleted_at)) {
                    throw new BusinessException(ApiCodeEnum::SERVICE_FRIEND_ALREADY_EXISTS);
                }
                $friend->type = FriendEnum::TYPE_APPLY;
                $friend->status = FriendEnum::STATUS_CHECK;
                $friend->deleted_at = null;
                $friend->created_at = time();
                $friend->hide = 0;
                $friend->nickname = $params['nickname'];
                $friend->remark = $params['remark'];
                $friend->setting = $params['setting'];
                //对方有你好友直接通过
                if ($owner && !$owner->deleted_at) {
                    $friend->type = FriendEnum::TYPE_VERIFY;
                    $friend->status = FriendEnum::STATUS_PASS;
                }

                $friend->save();
            } else {
                //没申请过
                $friend = new Friend($params);
                $friend->owner = $params['user']->id;
                $friend->friend = $params['friend'];
                $friend->save();
            }
            User::incrUnread([$params['friend']], 'apply');
//        $this->delCache($params);
            $apply = $friend->toArray();
            Gateway::sendToUid($params['friend'], json_encode([
                'who' => WorkerManEnum::WHO_FRIEND,
                'action' => WorkerManEnum::ACTION_APPLY,
                'data' => [
                    'from' => [
                        'id' => $params['user']->id,
                        'nickname' => $params['user']->nickname,
                        'avatar' => $params['user']->avatar,
                    ],
                    'content' => '请求添加您为好友',
                    'keywords' => $params['user']->$source
                ]
            ], JSON_UNESCAPED_UNICODE));
            DB::commit();
            return $apply;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    /**
     * 通过好友申请
     * @param array $params
     * @return array|null
     * @throws BusinessException
     */
    public function verify(array $params): array|null
    {
        $fromUser = $params['user']->id;
        $toUser = $params['friend'];
        $time = time();
        $user = User::query()->find($toUser, ['nickname']);
        //备注相同就是没有备注
        if ($user->nickname == $params['nickname']) $params['nickname'] = '';
        DB::beginTransaction();
        try {
            $owner = Friend::query()->where('owner', $fromUser)->where('friend', $toUser)->first();
            if ($owner) {
                $owner->nickname = $params['nickname'];
                $owner->unread = 0;
                $owner->setting = $params['setting'];
                $owner->deleted_at = null;
                $owner->save();
            } else {
                $owner = new Friend($params);
                $owner->owner = $fromUser;
                $owner->friend = $toUser;
            }
            $owner->type = FriendEnum::TYPE_VERIFY;
            $owner->status = FriendEnum::STATUS_PASS;
            $owner->display = 1;
            $owner->content = FriendEnum::PASS_MESSAGE;
            $owner->time = $time;
            $owner->save();

            $friend = Friend::query()->where('owner', $toUser)->where('friend', $fromUser)->first();
            $friend->type = FriendEnum::TYPE_VERIFY;
            $friend->status = FriendEnum::STATUS_PASS;
            $friend->display = 1;
            $friend->unread = 1;
            $friend->content = FriendEnum::PASS_MESSAGE;
            $friend->time = $time;
            $friend->save();
            DB::commit();
            //发送好友申请通过消息
            $from = [
                'id' => $fromUser,
                'nickname' => $friend->nickname ?: $params['user']->nickname,
                'avatar' => $params['user']->avatar,
            ];
            $sendData = [
                'who' => WorkerManEnum::WHO_MESSAGE,
                'action' => WorkerManEnum::ACTION_SEND,
                'data' => [
                    'from' => $from,
                    'from_user' => $fromUser,
                    'to_user' => $toUser,
                    'content' => FriendEnum::PASS_MESSAGE,
                    'type' => MessageEnum::TEXT,
                    'file' => [],
                    'extends' => [],
                    'pid' => 0,
                    'is_tips' => 0,
                    'is_undo' => 0,
                    'pcontent' => '',
                    'at_users' => [],
                    'is_group' => MessageEnum::PRIVATE,
                    'right' => false,
                    'time' => $time,
                ]
            ];
            $data = [
                'from_user' => $fromUser,
                'to_user' => $toUser,
                'content' => FriendEnum::PASS_MESSAGE,
                'is_group' => MessageEnum::PRIVATE,
                'type' => MessageEnum::TEXT,
                'created_at' => $time
            ];
            $sendData['data']['id'] = Message::query()->insertGetId($data);
            Gateway::sendToUid($toUser, json_encode($sendData, JSON_UNESCAPED_UNICODE));
//            $this->delCache($params);
            return $owner->toArray();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    /**
     * 更新好友设置
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function update(array $params): array
    {
        $allowField = ['nickname', 'desc', 'setting', 'unread'];
        $friend = $params['friend'];
        $owner = $params['user']->id;
        $friend = Friend::query()->where('owner', $owner)->where('friend', $friend)->first();
        if (!$friend) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);

        foreach ($allowField as $field) {
            if (!empty($params[$field])) {
                $friend->$field = $params[$field];
            }
        }
        $friend->save();
//        $this->delCache($params);
        return [];
    }

    private function delCache(array $params): void
    {
        $delKeys = [
            sprintf(RedisFriendEnum::LIST, $params['friend']),
            sprintf(RedisFriendEnum::LIST, $params['user']->id),
            sprintf(RedisFriendEnum::APPLY_LIST, $params['friend']),
            sprintf(RedisFriendEnum::APPLY_LIST, $params['user']->id),
        ];
        $this->forgetRememberCache(RedisFriendEnum::STORE, ...$delKeys);
    }
}
