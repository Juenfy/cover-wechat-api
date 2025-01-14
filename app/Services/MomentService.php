<?php

namespace App\Services;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\MomentEnum;
use App\Enums\WorkerManEnum;
use App\Exceptions\BusinessException;
use App\Models\Friend;
use App\Models\Moment;
use App\Models\MomentFiles;
use App\Models\MomentMessages;
use App\Models\User;
use GatewayWorker\Lib\Gateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class MomentService extends BaseService
{
    /**
     * 朋友圈列表
     * @param array $params
     * @return array
     */
    public function list(array $params): array
    {
        $owner = $params['user']->id;
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;
        $userIds = Friend::getMomentCanSeeFriendIds($owner);
        $userIds[] = $owner;
        return Moment::getMomentsPageByUserIds($userIds, $page, $limit);
    }

    /**
     * 朋友圈详情
     * @param array $params
     * @return array
     */
    public function detail(array $params): array
    {
        $owner = $params['user']->id;
        $userIds = Friend::getMomentCanSeeFriendIds($owner);
        $userIds[] = $owner;
        return Moment::getMomentById($params['id'], $userIds);
    }

    /**
     * 发朋友圈
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function publish(array $params): array
    {
        $momentData = [
            'user_id' => $params['user']->id,
            'type' => $params['type'],
            'content' => $params['content'],
            'created_at' => $this->time,
        ];
        DB::beginTransaction();
        try {
            $id = Moment::query()->insertGetId($momentData);
            if (!empty($params['files'])) {
                $fileService = new FileService();
                $batchFileData = [];
                foreach ($params['files'] as $file) {
                    $file = $fileService->uploadFile($file, false);
                    $batchFileData[] = [
                        'moment_id' => $id,
                        'file_id' => $file['id'],
                        'created_at' => $this->time,
                    ];
                }
                MomentFiles::query()->insert($batchFileData);
            }
            DB::commit();
            return ['id' => $id];
        } catch (\Exception $e) {
            DB::rollBack();
            $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    /**
     * 喜欢朋友圈
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function like(array $params): array
    {
        $moment = $this->getMomentById($params['id']);
        $likeData = [
            'moment_id' => $params['id'],
            'from_user' => $params['user']->id,
            'type' => MomentEnum::LIKE,
            'created_at' => $this->time,
        ];
        DB::beginTransaction();
        try {
            $likeData['id'] = MomentMessages::query()->insertGetId($likeData);
            $likeData['from'] = [
                'id' => $params['user']->id,
                'nickname' => $params['user']->nickname,
                'avatar' => $params['user']->avatar,
                'wechat' => $params['user']->wechat
            ];
            $sendData = [
                'who' => WorkerManEnum::WHO_MOMENT,
                'action' => WorkerManEnum::ACTION_LIKE,
                'data' => $likeData
            ];
            //获取并通知有评论或点赞过这条朋友圈的共同好友
            $noticePublicFriendIds = Moment::getNoticePublicFriendIds($params['id'], $params['user']->id, $moment->user_id);
            User::incrUnread($noticePublicFriendIds, 'moment.num', $params['user']->id);
            foreach ($noticePublicFriendIds as $friendId) {
                Gateway::sendToUid($friendId, json_encode($sendData, JSON_UNESCAPED_UNICODE));
            }
            DB::commit();
            return $likeData;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    /**
     * 取消喜欢
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function unlike(array $params): array
    {
        $like = MomentMessages::query()->where('type', MomentEnum::LIKE)->where('moment_id', $params['id'])
            ->where('from_user', $params['user']->id)->first();
        if (!$like) {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }
        $like->delete();
        return ['moment_id' => $params['id'], 'like_id' => $like->id];
    }

    /**
     * 评论朋友圈
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function comment(array $params): array
    {
        $moment = $this->getMomentById($params['id']);
        $fromUser = $params['user']->id;
        $toUser = $params['to_user'];
        $content = $params['content'];
        $commentData = [
            'moment_id' => $params['id'],
            'from_user' => $fromUser,
            'to_user' => $toUser,
            'type' => MomentEnum::COMMENT,
            'content' => $content,
            'created_at' => $this->time,
        ];
        DB::beginTransaction();
        try {
            $commentData['id'] = MomentMessages::query()->insertGetId($commentData);
            $commentData['from'] = [
                'id' => $params['user']->id,
                'nickname' => $params['user']->nickname,
                'avatar' => $params['user']->avatar,
                'wechat' => $params['user']->wechat
            ];
            $commentData['to'] = [];
            if ($toUser) {
                $user = User::query()->find($toUser);
                $commentData['to'] = [
                    'id' => $user->id,
                    'nickname' => $user->nickname,
                    'avatar' => $user->avatar,
                    'wechat' => $user->wechat
                ];
            }
            $sendData = [
                'who' => WorkerManEnum::WHO_MOMENT,
                'action' => WorkerManEnum::ACTION_COMMENT,
                'data' => $commentData
            ];


            //获取并通知有评论或点赞过这条朋友圈的共同好友
            $noticePublicFriendIds = Moment::getNoticePublicFriendIds($params['id'], $fromUser, $moment->user_id, $toUser);
            User::incrUnread($noticePublicFriendIds, 'moment.num', $fromUser);
            foreach ($noticePublicFriendIds as $friendId) {
                Gateway::sendToUid($friendId, json_encode($sendData, JSON_UNESCAPED_UNICODE));
            }
            DB::commit();
            return $commentData;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }

    /**
     * 获取朋友圈点赞、评论消息列表
     * @param array $params
     * @return array
     */
    public function message(array $params): array
    {
        $owner = $params['user']->id;
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;
        $userIds = Friend::getMomentCanSeeFriendIds($owner);
        $userIds[] = $owner;
        return Moment::getMomentsMessagePageByUserId($userIds, $page, $limit);
    }

    /**
     * 删除朋友圈
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function delete(array $params): array
    {
        $moment = $this->getMomentById($params['id']);
        if ($params['user']->id != $moment->user_id) {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }
        DB::beginTransaction();
        try {
            Moment::query()->where('id', $params['id'])->delete();
            MomentMessages::query()->where('moment_id', $params['id'])->delete();
            MomentFiles::query()->where('moment_id', $params['id'])->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
        }
        return ['id' => $params['id']];
    }

    /**
     * @param int $id
     * @return Model|Collection|Builder|array|null
     * @throws BusinessException
     */
    private function getMomentById(int $id): \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Builder|array|null
    {
        $moment = Moment::query()->find($id);
        if (!$moment) {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }
        return $moment;
    }
}
