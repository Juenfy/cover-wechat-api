<?php

namespace App\Services;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\GroupEnum;
use App\Enums\Database\MessageEnum;
use App\Exceptions\BusinessException;
use App\Models\Friend;
use App\Models\Group;
use App\Models\GroupUser;
use App\Models\User;

class ChatService extends BaseService
{
    /**
     * 聊天列表
     * @param array $params
     * @return array
     */
    public function list(array $params): array
    {
        $fromUser = $params['user']->id;

        //私聊
        $privateChatList = Friend::query()
            ->with(['friend' => function ($query) {
                $query->select(['id', 'avatar', 'nickname']);
            }])
            ->select(['content', 'time', 'unread', 'top', 'nickname', 'friend'])
            ->where('owner', $fromUser)
            ->where('display', 1)
            ->get()->toArray();

        foreach ($privateChatList as &$item) {
            $item['id'] = md5(MessageEnum::PRIVATE . $fromUser . $item['friend']['id']);
            $item['nickname'] = $item['nickname'] ?: $item['friend']['nickname'];
            $item['friend']['avatars'] = [$item['friend']['avatar']];
            $item['to'] = $item['friend'];
            $item['to_user'] = $item['to']['id'];
            $item['is_group'] = MessageEnum::PRIVATE;
            $item['muted'] = false;
            $item['at'] = false;
            unset($item['from']['avatar'], $item['friend']);
        }
        unset($item);

        //群聊
        $groupChatList = GroupUser::query()
            ->with(['group' => function ($query) {
                $query->with(['send' => function ($query) {
                    $query->select(['id', 'nickname']);
                }, 'friend' => function ($query) {
                    $query->select(['friend', 'nickname']);
                }])->select(['id', 'time', 'send_user', 'name', 'at_users']);
            }])
            ->select(['unread', 'top', 'group_id', 'user_id', 'name', 'nickname', 'content'])
            ->where('user_id', $fromUser)
            ->where('display', 1)
            ->get()->toArray();

        //群聊头像
        $groupIds = array_column($groupChatList, 'group_id');
        $groupUserList = GroupUser::query()
            ->with(['user' => function ($query) {
                $query->select(['id', 'avatar']);
            }])
            ->whereIn('group_id', $groupIds)
            ->orderByDesc('group_id')
            ->orderByDesc('created_at')
            ->get(['group_id', 'user_id'])->toArray();
        $groupAvatars = [];
        foreach ($groupUserList as $groupUser) {
            //群聊最多四个头像
            if (isset($groupAvatars[$groupUser['group_id']]) && count($groupAvatars[$groupUser['group_id']]) > 3) {
                continue;
            }
            $groupAvatars[$groupUser['group_id']][] = $groupUser['user']['avatar'];
        }
        unset($groupUserList, $groupUser);

        foreach ($groupChatList as &$item) {
            $item['id'] = md5(MessageEnum::GROUP . $fromUser . $item['group']['id']);
            $nickname = $item['nickname'];
            if (empty($nickname)) {
                $nickname = $item['group']['friend']['nickname'] ?: $item['group']['send']['nickname'];
            }
            $item['nickname'] = $item['name'] ?: $item['group']['name'];
            $item['content'] = $nickname . '：' . $item['content'];
            $item['time'] = $item['group']['time'];
            $item['to_user'] = $item['group_id'];
            $item['is_group'] = MessageEnum::GROUP;
            $item['to'] = [
                'id' => $item['group_id'],
                'avatars' => $groupAvatars[$item['group_id']] ?? []
            ];
            $item['muted'] = false;
            $item['at'] = false;
            //被@了
            $atUsers = explode(',', $item['group']['at_users']);
            if (in_array($fromUser, $atUsers)) {
                $item['content'] = '[有人@你了]';
                $item['at'] = true;
            }
            unset($item['send'], $item['group'], $item['group_id'], $item['from_user'], $item['user_id'], $item['name']);
        }

        return array_merge($privateChatList, $groupChatList);
    }

    /**
     * 聊天详情
     * @param array $params
     * @return array
     */
    public function info(array $params): array
    {
        $fromUser = $params['user']->id;
        $isGroup = intval($params['is_group']);
        $toUser = intval($params['to_user']);
        $chatInfo = [
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'is_group' => $isGroup,
            'users' => []
        ];
        $user = User::query()->find($fromUser, ['bg_file_path']);
        if ($isGroup == MessageEnum::GROUP) {
            $groupUser = GroupUser::query()
                ->with(['group' => function ($query) {
                    $query->select(['id', 'name', 'notice']);
                }])
                ->where('group_id', $toUser)
                ->where('user_id', $fromUser)
                ->first(['group_id', 'user_id', 'name', 'unread', 'top', 'muted', 'nickname', 'display_nickname', 'bg_file_path', 'role'])->toArray();
            $groupUserList = GroupUser::query()->with(['user' => function ($query) {
                $query->select(['id', 'nickname', 'avatar', 'wechat', 'bg_file_path']);
            }])->where('group_id', $toUser)->get(['group_id', 'user_id'])->toArray();
            foreach ($groupUserList as $groupUserItem) {
                $chatInfo['users'][] = $groupUserItem['user'];
            }
            $userCnt = count($chatInfo['users']);
            $chatInfo['nickname'] = ($groupUser['name'] ?: $groupUser['group']['name']) . "({$userCnt})";
            $chatInfo['unread'] = $groupUser['unread'];
            $chatInfo['muted'] = (bool)$groupUser['muted'];
            $chatInfo['top'] = (bool)$groupUser['top'];
            $chatInfo['display_nickname'] = (bool)$groupUser['display_nickname'];
            $chatInfo['bg_file_path'] = $groupUser['bg_file_path'] ?: $user->bg_file_path;
            $chatInfo['group_name'] = $groupUser['group']['name'];
            $chatInfo['group'] = [
                'name' => $groupUser['name'],
                'nickname' => trim($groupUser['nickname']) ? $groupUser['nickname'] : $params['user']->nickname,
                'notice' => $groupUser['group']['notice'],
            ];
            $chatInfo['role'] = $groupUser['role'];
        } else {
            $friend = Friend::query()
                ->with(['to' => function ($query) {
                    $query->select(['id', 'nickname', 'avatar', 'wechat']);
                }])
                ->where('owner', $fromUser)
                ->where('friend', $toUser)
                ->first(['owner', 'friend', 'nickname', 'unread', 'top', 'muted', 'bg_file_path'])->toArray();
            $chatInfo['nickname'] = $friend['nickname'] ?: $friend['to']['nickname'];
            $chatInfo['unread'] = $friend['unread'];
            $chatInfo['muted'] = (bool)$friend['muted'];
            $chatInfo['top'] = (bool)$friend['top'];
            $chatInfo['bg_file_path'] = $friend['bg_file_path'] ?: $user->bg_file_path;
            $chatInfo['users'][] = $friend['to'];
        }
        return $chatInfo;
    }

    /**
     * 置顶聊天
     * @param array $params
     * @return array
     */
    public function top(array $params): array
    {
        $isGroup = $params['is_group'];
        $toUser = $params['to_user'];
        $fromUser = $params['user']->id;
        $isTop = $params['is_top'];
        $time = $isTop > 0 ? $this->time : 0;
        if ($isGroup == MessageEnum::GROUP) {
            GroupUser::query()
                ->where('group_id', $toUser)
                ->where('user_id', $fromUser)
                ->update(['top' => $time]);
        } else {
            Friend::query()
                ->where('owner', $fromUser)
                ->where('friend', $toUser)
                ->update(['top' => $time]);
        }

        return [
            'is_group' => $isGroup,
            'to_user' => $toUser,
            'from_user' => $fromUser,
            'top' => $time
        ];
    }

    /**
     * 隐藏聊天
     * @param array $params
     * @return array
     */
    public function hide(array $params): array
    {
        $isGroup = $params['is_group'];
        $toUser = $params['to_user'];
        $fromUser = $params['user']->id;
        if ($isGroup == MessageEnum::GROUP) {
            GroupUser::query()
                ->where('group_id', $toUser)
                ->where('user_id', $fromUser)
                ->update(['display' => 0]);
        } else {
            Friend::query()
                ->where('owner', $fromUser)
                ->where('friend', $toUser)
                ->update(['display' => 0]);
        }

        return [
            'is_group' => $isGroup,
            'to_user' => $toUser,
            'from_user' => $fromUser
        ];
    }

    /**
     * 设置聊天
     * @param array $params
     * @throws BusinessException
     */
    public function update(array $params): void
    {
        $fromUser = $params['user']->id;
        $toUser = $params['to_user'];
        $isGroup = $params['is_group'];
        $paramKeys = array_keys($params);
        $commonFields = ['top', 'muted', 'bg_file_id', 'bg_file_path'];
        if ($isGroup == MessageEnum::GROUP) {
            $groupFields = ['notice', 'group_name'];
            //修改群信息需要群主、管理员身份
            if (array_intersect($paramKeys, $groupFields)) {
                $groupUserRole = GroupUser::query()->where('group_id', $toUser)->where('user_id', $fromUser)->value('role');
                if ($groupUserRole == GroupEnum::ROLE_USER) {
                    $this->throwBusinessException(ApiCodeEnum::SERVICE_GROUP_USER_NO_PERM);
                }
            }
            $groupUserFields = array_merge($commonFields, ['display_nickname', 'name', 'nickname']);
            if (!array_intersect($paramKeys, array_merge($groupFields, $groupUserFields))) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            $updateData = [
                'group' => [],
                'group_user' => []
            ];
            foreach ($params as $key => $value) {
                if (in_array($key, $groupFields)) {
                    $updateData['group'][$key == 'group_name' ? 'name' : $key] = is_bool($value) ? intval($value) : $value;
                }
                if (in_array($key, $groupUserFields)) {
                    if ($key === 'bg_file_path') $value = str_replace(env('STATIC_FILE_URL'), '', $value);
                    $updateData['group_user'][$key] = is_bool($value) ? intval($value) : $value;
                }
            }
            if ($updateData['group']) {
                Group::query()->where('id', $toUser)->update($updateData['group']);
            }
            if ($updateData['group_user']) {
                GroupUser::query()
                    ->where('group_id', $toUser)
                    ->where('user_id', $fromUser)
                    ->update($updateData['group_user']);
            }
        } else {
            if (!array_intersect($paramKeys, $commonFields)) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            $updateData = [];
            foreach ($params as $key => $value) {
                if (in_array($key, $commonFields)) {
                    if ($key === 'bg_file_path') $value = str_replace(env('STATIC_FILE_URL'), '', $value);
                    $updateData[$key] = is_bool($value) ? intval($value) : $value;
                }
            }
            Friend::query()
                ->where('owner', $fromUser)
                ->where('friend', $toUser)
                ->update($updateData);
        }
    }

    /**
     * 删除聊天
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function delete(array $params): array
    {
        $isGroup = $params['is_group'];

        $toUser = (int)$params['to_user'];
        $fromUser = (int)$params['user']->id;
        $this->clearRecord($fromUser, $toUser, $isGroup);
        return [
            'is_group' => $isGroup,
            'to_user' => $toUser,
            'from_user' => $fromUser
        ];
    }
}
