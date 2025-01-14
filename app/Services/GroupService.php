<?php

namespace App\Services;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\FriendEnum;
use App\Enums\Database\GroupEnum;
use App\Enums\Database\MessageEnum;
use App\Exceptions\BusinessException;
use App\Models\Friend;
use App\Models\Group;
use App\Models\GroupUser;
use Illuminate\Support\Facades\DB;

class GroupService extends BaseService
{
    /**
     * 群聊列表
     * @param array $params
     * @return array
     */
    public function list(array $params): array
    {
        $userId = (int)$params['user']->id;
        $groupInfoList = GroupUser::query()
            ->where('user_id', $userId)
            ->get(['group_id', 'name'])->toArray();
        $groupIds = array_column($groupInfoList, 'group_id');
        $groupInfoList = array_column($groupInfoList, null, 'group_id');
        $groupUserList = GroupUser::query()
            ->with(['user' => function ($query) {
                $query->select(['id', 'avatar']);
            }])
            ->whereIn('group_id', $groupIds)
            ->orderByDesc('group_id')
            ->orderByDesc('created_at')
            ->get(['group_id', 'user_id'])->toArray();
        if (empty($groupUserList)) return [];
        $groupAvatars = [];
        foreach ($groupUserList as $groupUser) {
            //群聊最多四个头像
            if (isset($groupAvatars[$groupUser['group_id']]) && count($groupAvatars[$groupUser['group_id']]) > 3) {
                continue;
            }
            $groupAvatars[$groupUser['group_id']][] = $groupUser['user']['avatar'];
        }

        unset($groupUserList, $groupUser);
        $groupList = Group::query()
            ->whereIn('id', $groupIds)
            ->get()->toArray();
        $data = [];
        foreach ($groupList as $group) {
            $nickname = $group['name'];
            if (!empty($groupInfoList[$group['id']]['name'])) {
                $nickname = $groupInfoList[$group['id']]['name'];
            }
            $data[] = [
                'id' => $group['id'],
                'nickname' => $nickname,
                'is_group' => MessageEnum::GROUP,
                'to' => [
                    'id' => $group['id'],
                    'avatars' => $groupAvatars[$group['id']] ?? [],
                ]
            ];
        }

        return $data;
    }

    /**
     * 创建群聊或邀请好友进群
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function action(array $params): array
    {
        $userId = $params['user']->id;
        $action = $params['action'];
        $groupId = $params['group_id'] ?? 0;
        $name = "群聊";
        if ($action == GroupEnum::ACTION_INVITE) {
            empty($groupId) && $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            $group = Group::query()->find($groupId, ['name']);
            empty($group) && $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            $name = $group->name;
        }

        // 过滤不是好友的用户
        $friendList = Friend::query()->with(['to' => function ($query) {
            $query->select(['id', 'nickname']);
        }])->where('owner', $userId)
            ->whereIn('friend', $params['group_users'])
            ->where('status', FriendEnum::STATUS_PASS)
            ->get(['id', 'nickname', 'friend'])->toArray();

        $groupUsers = [$userId];
        $groupUsers = array_merge($groupUsers, array_column($friendList, 'friend'));

        $data = [];
        DB::beginTransaction();
        try {
            $id = $groupId;
            $content = "{$params['user']->nickname}创建了群聊‘{$name}’";
            if ($action == GroupEnum::ACTION_CREATE) {
                $groupData = [
                    'name' => $name,
                    'owner' => $userId,
                    'notice' => '',
                    'send_user' => $userId,
                    'content' => $content,
                    'time' => $this->time,
                    'setting' => json_encode([]),
                    'created_at' => $this->time,
                ];
                $id = Group::query()->insertGetId($groupData);
                //把ai小助手加入群聊
                (new AssistantService())->joinGroupWhenCreateGroup($id);
            }

            $batch = [];
            foreach ($groupUsers as $groupUser) {
                if ($action == GroupEnum::ACTION_INVITE && $groupUser == $userId) continue;
                $batch[] = [
                    'group_id' => $id,
                    'user_id' => $groupUser,
                    'role' => $groupUser == $userId ? GroupEnum::ROLE_SUPER : GroupEnum::ROLE_USER,
                    'invite_id' => $userId,
                    'display' => 1,
                    'unread' => 1,
                    'setting' => json_encode([]),
                    'deleted_at' => 0,
                    'created_at' => $this->time,
                ];
            }
            GroupUser::query()->upsert($batch, ['group_id', 'user_id']);
            $data = ['group_id' => $id, 'group_users' => $groupUsers, 'group_name' => $name, 'action' => $action];
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
        return $data;
    }
}
