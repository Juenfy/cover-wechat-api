<?php

namespace App\Services;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\FileEnum;
use App\Enums\Database\FriendEnum;
use App\Enums\Database\MessageEnum;
use App\Enums\Database\MoneyFlowLogEnum;
use App\Enums\Database\RedPacketEnum;
use App\Enums\Database\UserEnum;
use App\Enums\Redis\ChatEnum;
use App\Enums\WorkerManEnum;
use App\Exceptions\BusinessException;
use App\Jobs\AssistantReplyJob;
use App\Models\File;
use App\Models\Friend;
use App\Models\Group;
use App\Models\GroupUser;
use App\Models\Message;
use App\Models\MoneyFlowLog;
use App\Models\RedPacket;
use App\Models\User;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Facades\DB;

class MessageService extends BaseService
{

    /**
     * 聊天消息列表
     * @param array $params
     * @return array
     */
    public function list(array $params): array
    {
        $fromUser = (int)$params['user']->id;
        $toUser = (int)$params['to_user'];
        $me = [
            'id' => $fromUser,
            'nickname' => $params['user']->nickname,
            'avatar' => $params['user']->avatar,
            'wechat' => $params['user']->wechat,
        ];

        $list = [];
        //聊天记录
        $messages = Message::query()
            ->whereRaw("((from_user = {$fromUser} AND to_user = {$toUser} AND is_group = 0) OR (from_user = {$toUser} AND to_user = {$fromUser} AND is_group = 0) OR (to_user = {$toUser} AND is_group=1))")
            ->whereRaw("(FIND_IN_SET('{$fromUser}', deleted_users) = '')")
            ->orderBy('created_at')
            ->get()
            ->toArray();
        if (!$messages) return $list;
        //引用消息
        $parentIds = array_column($messages, 'pid');
        $parentIds = array_filter($parentIds);
        $parentMessages = [];
        if ($parentIds) {
            $parentMessages = Message::with(['from' => function ($query) {
                return $query->select(['id', 'nickname', 'avatar', 'wechat']);
            }])->whereIn('id', $parentIds)->get()->toArray();
            $parentMessages = array_column($parentMessages, null, 'id');
            $files = $this->getFiles($parentMessages);
            $redPackets = $this->getRedPackets($parentMessages);
            foreach ($parentMessages as $pk => $parentMessage) {
                $parentMessages[$pk] = $this->handleMessage($parentMessage, $toUser, 0, $files, $redPackets);
            }
        }

        $files = $this->getFiles($messages);
        $redPackets = $this->getRedPackets($messages);
        $redPacketRecords = $this->getRedPacketRecords($messages);
        foreach ($messages as $message) {
            $item = $this->handleMessage($message, $toUser, 0, $files, $redPackets);
            $atUsers = explode(',', $message['at_users']);
            $atUsers = array_filter($atUsers);
            $item['at_users'] = array_map('intval', $atUsers);
            !empty($parentMessages[$message['pid']]) && $item['parent'] = $parentMessages[$message['pid']];
            $item['right'] = $message['from_user'] == $fromUser;

            //红包状态处理
            if (!empty($item['red_packet'])) {
                $redPacketId = $item['red_packet']['id'];
                $receiveUserIds = $redPacketRecords[$redPacketId] ?? [];
                if (in_array($fromUser, $receiveUserIds)) {
                    $item['red_packet']['status'] = -2; //已领取
                }
                if ($item['red_packet']['from_user'] == $fromUser && $item['red_packet']['status'] == 1 && $item['red_packet']['stock'] <= 0) {
                    $item['red_packet']['status'] = -1; //已被领取
                }
                if ($item['red_packet']['status'] == 1 && $item['red_packet']['overdued_at'] < $this->time) {
                    $item['red_packet']['status'] = -3; //已过期
                }
                //别人的专属红包
                if ($item['red_packet']['status'] == 1 && $item['red_packet']['group_id'] && $item['red_packet']['to_user'] && $item['red_packet']['to_user'] != $fromUser) {
                    $item['red_packet']['status'] = -4; //无法领取
                }
                if ($item['red_packet']['status'] == 1 && $item['red_packet']['stock'] <= 0) {
                    $item['red_packet']['status'] = -5; //被抢光了
                }
            }

            $list[] = $item;
        }
        unset($message, $messages);

        if ($params['is_group'] == MessageEnum::GROUP) {
            //群聊
            $userIds = array_column($list, 'from_user');
            $userIds = array_unique($userIds);
            $userList = User::query()->whereIn('id', $userIds)->get(['id', 'nickname', 'avatar', 'wechat'])->toArray();

            //本群显示昵称 显示优先级最高
            $groupUserList = GroupUser::query()
                ->where('group_id', $toUser)
                ->whereIn('user_id', $userIds)
                ->get(['nickname', 'user_id'])->toArray();
            //朋友昵称 显示优先级第二

            $friendList = Friend::query()
                ->where('owner', $fromUser)
                ->whereIn('friend', $userIds)
                ->where('status', FriendEnum::STATUS_PASS)
                ->get(['nickname', 'friend'])->toArray();

            $groupUserList = array_column($groupUserList, 'nickname', 'user_id');
            $friendList = array_column($friendList, 'nickname', 'friend');

            //处理群成员昵称显示 默认用户昵称
            foreach ($userList as &$user) {
                if (!empty($friendList[$user['id']])) {
                    $user['nickname'] = $friendList[$user['id']];
                }
                if (!empty($groupUserList[$user['id']])) {
                    $user['nickname'] = $groupUserList[$user['id']];
                }
            }
            unset($user, $groupUserList, $friendList, $userIds);

            $userList = array_column($userList, null, 'id');

            foreach ($list as &$item) {
                //撤回处理
                if ($item['is_undo']) {
                    $who = $item['from_user'] == $fromUser ? "你" : $userList[$fromUser]['nickname'];
                    $item['content'] = $who . "撤回了一条消息";
                }
                $item['from'] = $userList[$item['from_user']];
            }
            unset($item);
            //标记已读
            GroupUser::query()->where('user_id', $fromUser)->where('group_id', $toUser)->update(['unread' => 0]);
        } else {
            //私聊
            $user = User::query()->where('id', $toUser)->first(['id', 'nickname', 'avatar', 'wechat']);
            $friend = Friend::query()->where('owner', $fromUser)
                ->where('friend', $toUser)
                ->first(['id', 'nickname']);
            $user->nickname = $friend->nickname ?: $user->nickname;
            foreach ($list as &$item) {
                //撤回处理
                if ($item['is_undo']) {
                    $who = $item['from_user'] == $fromUser ? "你" : $user->nickname;
                    $item['content'] = $who . "撤回了一条消息";
                }
                $item['from'] = $item['from_user'] == $fromUser ? $me : $user->toArray();
            }
            unset($item);
            //标记已读
            Friend::query()->where('owner', $fromUser)->where('friend', $toUser)->update(['unread' => 0]);
        }
        return $list;
    }

    /**
     * 发送消息
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function send(array $params): array
    {
        $isGroup = intval($params['is_group']);
        if (!in_array($params['type'], MessageEnum::TYPE)
            || !in_array($isGroup, MessageEnum::IS_GROUP)) {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }
        $fromUser = $params['user']->id;
        $toUser = $params['to_user'];
        $message = $this->handleMessage($params, $toUser, $fromUser);
        $message['time'] = $this->time;
        $assistantIds = get_assistant_ids();
        $atUserStr = $params['at_users'] ?? '';
        $atUsers = explode(',', $atUserStr);
        if ($atUsers) {
            $atUsers = array_filter($atUsers);
            $atUsers = array_diff($atUsers, [$fromUser]);
        }
        $sendData = [
            'who' => WorkerManEnum::WHO_MESSAGE,
            'action' => WorkerManEnum::ACTION_SEND,
            'data' => $message
        ];

        $data = [
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'content' => $params['content'],
            'is_group' => $isGroup,
            'type' => $params['type'],
            'pid' => $params['pid'] ?? 0,
            'at_users' => $atUserStr,
            'created_at' => $this->time
        ];

        //红包消息处理
        if ($params['type'] == MessageEnum::RED_PACKET) {
            $redPacketId = $params['red_packet_id'];
            if (empty($redPacketId)) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            $redPacket = RedPacket::with(['from' => function ($query) {
                $query->select(['id', 'nickname', 'avatar']);
            }])->find($redPacketId)->toArray();
            if ($redPacket) {
                $redPacket['status'] = 1;
                $data['red_packet_id'] = $redPacketId;
                $sendData['data']['red_packet'] = $redPacket;
                $data['content'] = $redPacket['remark'];
                $sendData['data']['content'] = $redPacket['remark'];
            }
        }

        //文件消息处理
        if (in_array($params['type'], FileEnum::TYPE)) {
            if (empty($params['file_id'])) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            $fileId = $params['file_id'];
            $file = File::query()->find($fileId);
            if ($file) {
                $data['file_id'] = $file->id;
                $data['file_name'] = $file->name;
                $data['file_type'] = $file->type;
                $data['file_size'] = $file->size;
//                $sendData['data']['extends'] = [
//                    'path' => str_replace(env('STATIC_FILE_URL'), '', $file->path),
//                    'format' => $file->format,
//                    'width' => $file->width,
//                    'height' => $file->height,
//                    'duration' => $file->duration
//                ];
                $sendData['data']['file'] = [
                    'id' => $fileId,
                    'name' => $file->name,
                    'type' => $file->type,
                    'size' => $file->size
                ];
//                $data['extends'] = json_encode([]);
                $sendData['data']['content'] = $file->path;
            }
        }

        //消息内容简称处理
        if ($params['type'] !== MessageEnum::TEXT) {
            $data['content'] = MessageEnum::SIMPLE_CONTENT[$params['type']];
        }

        DB::beginTransaction();
        try {
            if ($isGroup == MessageEnum::GROUP) {
                $group = Group::query()->find($toUser);
                if ($atUsers) {
                    $atUsers = array_map('intval', $atUsers);
                    $originAtUsers = explode(',', $group->at_users);
                    $updateAtUsers = array_unique(array_merge($originAtUsers, $atUsers));
                    $group->at_users = implode(',', $updateAtUsers);
                }
                $group->send_user = $fromUser;
                $group->content = $data['content'];
                $group->time = $this->time;
                $group->save();
                GroupUser::query()
                    ->where('group_id', $toUser)
                    ->update([
                        'display' => 1
                    ]);
                GroupUser::query()
                    ->where('group_id', $toUser)
                    ->where('user_id', '<>', $fromUser)
                    ->increment('unread');
            } else {
                if (empty($params['id'])) {
                    Friend::query()
                        ->whereRaw("((owner = $fromUser AND friend = $toUser) OR (friend = $fromUser AND owner = $toUser))")
                        ->update([
                            'display' => 1,
                            'content' => $data['content'],
                            'time' => $this->time
                        ]);
                    Friend::query()
                        ->whereRaw("(friend = $fromUser AND owner = $toUser)")
                        ->increment('unread');
                }
            }

            //通话处理
            if (in_array($params['type'], [MessageEnum::VIDEO_CALL, MessageEnum::AUDIO_CALL])) {
                $data['content'] = $params['content'];
                $sendData['who'] = WorkerManEnum::WHO_USER;
                $sendData['action'] = WorkerManEnum::ACTION_CALL;
                !empty($params['action']) && $sendData['data']['action'] = $params['action'];
                !empty($params['offer']) && $sendData['data']['offer'] = $params['offer'];
                !empty($params['answer']) && $sendData['data']['answer'] = $params['answer'];
                !empty($params['candidate']) && $sendData['data']['candidate'] = $params['candidate'];
                if (empty($params['id'])) {
                    $data['deleted_users'] = "{$toUser}";
                } elseif ($params['id'] > 0 && empty($params['candidate'])) {
                    $sendData['data']['id'] = $params['id'];
                    $updateData = ['content' => $data['content']];
                    if (!empty($params['duration'])) {
                        $updateData['extends'] = json_encode(['duration' => $params['duration']]);
                    }
                    Message::query()->where('id', $params['id'])->update($updateData);
                }
            }

            if (empty($params['id'])) {
                $sendData['data']['id'] = Message::query()->insertGetId($data);
            }
            DB::commit();

            //at用户处理
            if ($atUsers) {
                $sendAtData = $sendData;
                $sendAtData['action'] = WorkerManEnum::ACTION_AT;
                //通知被at的用户
                foreach ($atUsers as $atUser) {
                    $sendAtData['data']['to_user'] = $atUser;
                    Gateway::sendToUid($atUser, json_encode($sendAtData, JSON_UNESCAPED_UNICODE));
                }
            }

            //引用消息处理
            if (!empty($params['pid'])) {
                $parentMessage = Message::with(['from' => function ($query) {
                    return $query->select(['id', 'nickname', 'avatar', 'wechat']);
                }])->find($params['pid']);
                $parentMessage = $parentMessage ? $parentMessage->toArray() : [];
                $files = $this->getFiles([$parentMessage]);
                $sendData['data']['parent'] = $this->handleMessage($parentMessage, $toUser, 0, $files);
                $sendQuoteData = [
                    'who' => WorkerManEnum::WHO_MESSAGE,
                    'action' => WorkerManEnum::ACTION_QUOTE,
                    'data' => []
                ];
                //通知被引用消息的用户
                Gateway::sendToUid($parentMessage['from_user'], json_encode(
                    $sendQuoteData,
                    JSON_UNESCAPED_UNICODE
                ));
            }

            //ai小助手处理
            $aiData = $sendData['data'];
            $aiIds = [];
            if ($isGroup == MessageEnum::PRIVATE && in_array($toUser, $assistantIds)) {
                //私聊ai小助手
                $aiIds[] = $toUser;
            }

            if ($atAiIds = array_intersect($assistantIds, $atUsers)) {
                //群聊@ai小助手
                $aiIds = array_merge($aiIds, $atAiIds);
            }

            if ($aiIds) {
                //ai小助手回复消息
                foreach ($aiIds as $aiId) {
                    $aiData['to_ai'] = $aiId;
                    $job = new AssistantReplyJob($aiData);
                    dispatch($job->onQueue(ChatEnum::ASSISTANT_REPLY));
                }
            }

            //向用户发送消息通知
            if ($isGroup == MessageEnum::GROUP) {
                $excludeClientId = Gateway::getClientIdByUid($fromUser);
                Gateway::sendToGroup($toUser, json_encode($sendData, JSON_UNESCAPED_UNICODE), $excludeClientId);
            } else {
                Gateway::sendToUid($toUser, json_encode($sendData, JSON_UNESCAPED_UNICODE));
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }

        $sendData['data']['right'] = true;
        $sendData['data']['from']['nickname'] = $params['user']->nickname;
        return $sendData['data'];
    }

    /**
     * 聊天消息已读
     * @param array $params
     * @return int
     */
    public function read(array $params): int
    {
        $fromUser = $params['user']->id;
        $toUser = $params['to_user'];
        $isGroup = $params['is_group'];
        if (!empty($params['id'])) {
            //单条at/引用消息标记已读
            $field = $params['type'] ?? 'at_users';
            $message = Message::query()->findOrFail($params['id']);
            $origin = explode(',', $message->$field);
            $update = array_diff($origin, [$fromUser]);
            $message->$field = implode(',', $update);
            $message->save();
            if (Message::query()->whereRaw("FIND_IN_SET($fromUser, {$field})")->count() <= 0) {
                //所有at/引用消息标记已读
                if ($isGroup == MessageEnum::GROUP) {
                    $group = Group::query()->findOrFail($toUser);
                    $origin = explode(',', $group->$field);
                    $update = array_diff($origin, [$fromUser]);
                    $group->$field = implode(',', $update);
                    $group->save();
                } else {
                    $friend = Friend::query()->where('owner', $fromUser)->where('friend', $toUser)->first();
                    $origin = explode(',', $friend->$field);
                    $update = array_diff($origin, [$fromUser]);
                    $friend->$field = implode(',', $update);
                    $friend->save();
                }
            }
            return 1;
        }
        if ($isGroup == MessageEnum::GROUP) {
            return GroupUser::query()->where('user_id', $fromUser)->where('group_id', $toUser)->update(['unread' => 0]);
        }
        return Friend::query()->where('owner', $fromUser)->where('friend', $toUser)->update(['unread' => 0]);
    }

    /**
     * 聊天消息撤回
     * @param array $params
     * @return bool
     * @throws BusinessException
     */
    public function undo(array $params): bool
    {
        $id = $params['id'];
        $message = Message::query()->find($id);
        if (!$message) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        $message->is_undo = 1;
        $message->is_tips = 1;
        $message->updated_at = $this->time;
        $message->save();
        return true;
    }

    /**
     * 未读聊天消息
     * @param int $userId
     * @return array
     */
    public function unread(int $userId): array
    {
        $group = GroupUser::query()
            ->where('user_id', $userId)
            ->where('display', 1)
            ->where('unread', '>', 0)
            ->sum('unread');
        $private = Friend::query()
            ->where('owner', $userId)
            ->where('display', 1)
            ->where('unread', '>', 0)
            ->sum('unread');
        $unread = User::getUnreadById($userId);
        $apply = $unread['apply'];
        $moment = $unread['moment'];

        $from = [];
        if ($moment['num'] > 0) {
            $from = User::query()->find($moment['from'], ['id', 'nickname', 'avatar', 'wechat']);
        }

        return [
            'chat' => $group + $private,
            'apply' => $apply,
            'friend' => $apply,
            'moment' => [
                'num' => $moment['num'],
                'from' => $from
            ],
            'discover' => $moment['num']
        ];
    }

    /**
     * 通用获取消息里的关联文件方法
     * @param array $messages
     * @return array
     */
    private function getFiles(array $messages): array
    {
        $fileIds = array_column($messages, 'file_id');
        $fileIds = array_filter($fileIds);
        $files = [];
        if ($fileIds) {
            $files = File::query()->whereIn('id', $fileIds)->get()->toArray();
            $files = array_column($files, null, 'id');
        }
        return $files;
    }

    /**
     * 通用获取消息里的关联红包方法
     * @param array $messages
     * @return array
     */
    private function getRedPackets(array $messages): array
    {
        $redPacketIds = array_column($messages, 'red_packet_id');
        $redPacketIds = array_filter($redPacketIds);
        $redPackets = [];
        if ($redPacketIds) {
            $redPackets = RedPacket::with(['from' => function ($query) {
                $query->select(['id', 'nickname', 'avatar']);
            }])->whereIn('id', $redPacketIds)->get()->toArray();
            $redPackets = array_column($redPackets, null, 'id');
        }
        return $redPackets;
    }

    /**
     * 通用获取领取红包记录
     * @param array $messages
     * @return array
     */
    private function getRedPacketRecords(array $messages): array
    {
        $redPacketIds = array_column($messages, 'red_packet_id');
        $redPacketIds = array_filter($redPacketIds);
        $redPacketRecords = [];
        if ($redPacketIds) {
            $list = MoneyFlowLog::query()
                ->where('type', MoneyFlowLogEnum::TYPE_RED_PACKET)
                ->where('change_type', UserEnum::MONEY_INCR)
                ->whereIn('from_id', $redPacketIds)
                ->get(['from_id', 'user_id'])
                ->toArray();
            foreach ($list as $item) {
                if (isset($redPacketRecords[$item['from_id']])) {
                    $redPacketRecords[$item['from_id']][] = $item['user_id'];
                } else {
                    $redPacketRecords[$item['from_id']] = [$item['user_id']];
                }
            }
        }
        return $redPacketRecords;
    }

    /**
     * 通用处理消息方法
     * @param array $message
     * @param int $toUser
     * @param int $fromUser
     * @param array $files
     * @param array $redPackets
     * @return array
     */
    private function handleMessage(array $message, int $toUser, int $fromUser, array $files = [], array $redPackets = []): array
    {

        $item = [
            'id' => $message['id'] ?? 0,
            'from' => $message['from'] ?? [],
            'from_user' => $message['from_user'] ?? $fromUser,
            'to_user' => $toUser,
            'content' => $message['content'],
            'type' => $message['type'],
            'is_undo' => $message['is_undo'] ?? 0,
            'is_tips' => $message['is_tips'] ?? 0,
            'time' => !empty($message['created_at']) ? strtotime($message['created_at']) : 0,
            'red_packet_id' => $message['red_packet_id'] ?? 0,
            'red_packet' => [],
            'file' => [],
            'extends' => [],
            'pid' => 0,
            'parent' => [],
            'at_users' => [],
            'is_group' => intval($message['is_group']),
            'right' => false
        ];
        if (in_array($message['type'], FileEnum::TYPE)) {
            $fileId = $message['file_id'];
            $file = $files[$fileId] ?? [];
            if ($file) {
                $item['content'] = $file['path'];
//                $item['extends'] = [
//                    'thumbnail' => $file['thumbnail_path'] ?: '',
//                    'format' => $file['format'],
//                    'width' => $file['width'],
//                    'height' => $file['height'],
//                    'duration' => $file['duration']
//                ];
                $item['file'] = [
                    'id' => $fileId,
                    'name' => $file['name'],
                    'type' => $file['type'],
                    'size' => $file['size']
                ];
            }
        }
        if ($message['type'] === MessageEnum::RED_PACKET) {
            $redPacketId = $message['red_packet_id'];
            $redPacket = $redPackets[$redPacketId] ?? [];
            if ($redPacket) {
                $item['content'] = $redPacket['remark'];
                $item['red_packet'] = $redPacket;
                $item['red_packet']['status'] = 1;
            }
        }
        return $item;
    }
}
