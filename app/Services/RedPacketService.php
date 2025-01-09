<?php

namespace App\Services;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\MoneyFlowLogEnum;
use App\Enums\Database\RedPacketEnum;
use App\Enums\Redis\UserEnum;
use App\Exceptions\BusinessException;
use App\Models\Friend;
use App\Models\GroupUser;
use App\Models\MoneyFlowLog;
use App\Models\RedPacket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RedisException;

class RedPacketService extends BaseService
{
    private \Redis $userRedis;

    private int $time;

    public function __construct()
    {
        $this->userRedis = Redis::connection(UserEnum::STORE)->client();
        $this->time = time();
    }

    public function recordList(array $params): array
    {
        $page = $params['page'] ?? 1;
        $limit = $params['limit'] ?? 10;
        $offset = ($page - 1) * $limit;
        $list = MoneyFlowLog::with(['user' => function ($query) {
            $query->select(['id', 'nickname', 'avatar']);
        }])
            ->where('from_id', $params['id'])
            ->where('type', MoneyFlowLogEnum::TYPE_RED_PACKET)
            ->where('change_type', \App\Enums\Database\UserEnum::MONEY_INCR)
            ->orderBy('money', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'money', 'user_id', 'created_at'])->toArray();
        foreach ($list as &$item) {
            $item['money'] = round($item['money'] / 100, 2);
            $item['created_at'] = date('Y-m-d H:i:s', $item['created_at']);
        }
        $total = MoneyFlowLog::query()->where('from_id', $params['id'])
            ->where('type', MoneyFlowLogEnum::TYPE_RED_PACKET)
            ->where('change_type', \App\Enums\Database\UserEnum::MONEY_INCR)->count();
        return [get_page_info($page, $limit, $total), $list];
    }

    /**
     * 发红包
     * @param array $params
     * @return array
     * @throws BusinessException
     */
    public function send(array $params): array
    {
        $fromUser = $params['user']->id;
        // 私聊红包
        if (empty($params['group_id']) && !empty($params['to_user'])) {
            // 非好友
            if (in_array($params['to_user'], get_assistant_ids()) || !Friend::checkIsFriend($params['to_user'], $fromUser)) {
                $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            }
            $params['type'] = RedPacketEnum::TYPE_BELONG;
            $params['total'] = 1;
        }
        if (!in_array($params['type'], RedPacketEnum::TYPE) || //红包类型校验
            $params['type'] == RedPacketEnum::TYPE_BELONG && $params['total'] > 1 || //专属红包数量不能大于1个
            !empty($params['to_user']) && $params['total'] > 1 || //私聊红包数量不能大于1个
            $params['money'] <= 0 || //红包金额要大于0
            $params['money'] / $params['total'] < 0.01 //最小红包金额不能小于0.01
        ) {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }

        // 群红包
        if (!empty($params['group_id'])) {
            // 非群成员
            if (!GroupUser::checkIsGroupMember($fromUser, $params['group_id'])) {
                $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            }
            // 红包数量不能大于群成员数
            $groupUserCnt = GroupUser::query()->where('group_id', $params['group_id'])->count();
            if ($params['total'] > $groupUserCnt) {
                $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
            }
        }

        DB::beginTransaction();
        try {
            $data = [
                'from_user' => $fromUser,
                'to_user' => $params['to_user'] ?? 0,
                'group_id' => $params['group_id'] ?? 0,
                'type' => $params['type'],
                'money' => $params['money'] * 100,
                'total' => $params['total'],
                'stock' => $params['total'],
                'remark' => $params['remark'] ?? '恭喜发财，大吉大利',
                'overdued_at' => $this->time + 86400,
                'created_at' => $this->time
            ];
            $id = RedPacket::query()->insertGetId($data);
            //发红包扣余额
            User::changeMoney($fromUser, $params['money'], \App\Enums\Database\UserEnum::MONEY_DECR, [
                'money_flow_type' => MoneyFlowLogEnum::TYPE_RED_PACKET,
                'from_id' => $id,
                'remark' => '发出红包'
            ]);
            DB::commit();
            $packetsInYuan = $this->generateRedPackets($params['money'], $params['total']);
            $key = sprintf(UserEnum::RED_PACKET, $id);
            $this->userRedis->multi();
            foreach ($packetsInYuan as $v) {
                $this->userRedis->sAdd($key, $v);
            }
            $this->userRedis->expire($key, $data['overdued_at']);
            $this->userRedis->exec();
            return ['id' => $id];
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessException(ApiCodeEnum::SYSTEM_ERROR, $e->getMessage());
        }
    }


    public function detail(array $params)
    {
        $redPacket = $this->check($params);
        return [
            'id' => $redPacket->id,
            'stock' => $redPacket->stock,
        ];
    }

    /**
     * 抢红包
     * @param array $params
     * @return array|void
     * @throws BusinessException
     * @throws RedisException
     */
    public function receive(array $params)
    {
        $fromUser = $params['user']->id;
        $id = $params['id'];
        $key = sprintf(UserEnum::RED_PACKET_RECEIVE_LOCK, $id);
        $lock = 0;
        // 允许等待争抢锁 5秒内没抢到视为超时
        while (true) {
            if (time() - $this->time > 5) {
                break;
            }
            $lock = $this->userRedis->setnx($key, 1);
            if ($lock) {
                $this->userRedis->expire($key, 10);
                break;
            }
        }
        if ($lock) {
            DB::beginTransaction();
            try {
                $redPacket = $this->check($params);
                $money = $this->userRedis->sPop(sprintf(UserEnum::RED_PACKET, $params['id']));
                User::changeMoney($fromUser, $money, \App\Enums\Database\UserEnum::MONEY_INCR, [
                    'money_flow_type' => MoneyFlowLogEnum::TYPE_RED_PACKET,
                    'from_id' => $redPacket->id,
                    'remark' => '领取红包'
                ]);
                $redPacket->stock -= 1;
                $redPacket->updated_at = time();
                $redPacket->save();
                DB::commit();
                $this->userRedis->del($key);
                return ['id' => $redPacket['id'], 'money' => $money, 'total' => $redPacket->total, 'stock' => $redPacket->stock];
            } catch (\Exception $e) {
                DB::rollBack();
                $this->userRedis->del($key);
                $this->throwBusinessException($e->getCode(), $e->getMessage());
            }
        } else {
            $this->throwBusinessException(ApiCodeEnum::FAILED_DEFAULT, '抢红包人数过多，请稍后再抢');
        }
    }

    /**
     * 通用校验
     * @throws BusinessException
     */
    private function check(array $params): \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Builder|array|null
    {
        $fromUser = $params['user']->id;
        $redPacket = RedPacket::query()->findOrFail($params['id']);
        $groupId = $redPacket->group_id;

        // 非群成员
        if ($groupId && !GroupUser::checkIsGroupMember($fromUser, $groupId)) {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }

        // 非好友
        if (!$groupId && !Friend::checkIsFriend($redPacket->from_user, $fromUser)) {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }

        // 专属红包校验不通过
        if ($redPacket->type === RedPacketEnum::TYPE_BELONG && $fromUser !== $redPacket->to_user) {
            $this->throwBusinessException(ApiCodeEnum::FAILED_DEFAULT, '无法领取别人的专属红包！');
        }

        // 是否已经领取过了
        $isReceive = MoneyFlowLog::query()
            ->where('type', MoneyFlowLogEnum::TYPE_RED_PACKET)
            ->where('change_type', \App\Enums\Database\UserEnum::MONEY_INCR)
            ->where('from_id', $redPacket->id)
            ->where('user_id', $fromUser)
            ->exists();

        if ($isReceive) {
            $this->throwBusinessException(ApiCodeEnum::FAILED_DEFAULT, '红包已经领取过了！');
        }

        // 过期了
        if ($redPacket->overdued_at < $this->time) {
            $this->throwBusinessException(ApiCodeEnum::FAILED_DEFAULT, '该红包已超过24小时，如已领取，可在“红包记录”中查看');
        }

        // 抢完了
        if ($redPacket->stock <= 0) {
            $this->throwBusinessException(ApiCodeEnum::FAILED_DEFAULT, '您手慢了！红包已经被抢完了！');
        }

        return $redPacket;
    }

    /**
     * 生成随机红包金额
     * @param int $totalAmount
     * @param int $numPackets
     * @return array
     */
    private function generateRedPackets(int $totalAmount, int $numPackets): array
    {
        // 将总金额转换为最小单位（分），避免浮动误差
        $totalAmountInCents = $totalAmount * 100; // 转换为分（最小单位）

        // 初始化红包数组
        $packets = [];
        $remainingAmountInCents = $totalAmountInCents;

        // 随机生成红包金额，确保每个红包至少 1 分
        for ($i = 0; $i < $numPackets - 1; $i++) {
            // 确保每个红包至少为 1 分，并且保证剩余的金额能分配给后续红包
            $maxAmountInCents = $remainingAmountInCents - ($numPackets - $i - 1); // 留下至少 1 分给其他红包
            if ($maxAmountInCents < 1) {
                $amountInCents = $remainingAmountInCents; // 如果剩余金额小于 1 分，直接分配剩余金额
            } else {
                // 生成随机红包金额，确保金额至少为 1 分
                $amountInCents = mt_rand(1, $maxAmountInCents);
            }

            $packets[] = $amountInCents;
            $remainingAmountInCents -= $amountInCents; // 减去已分配的金额
        }

        // 最后一个红包直接分配剩余金额
        $packets[] = $remainingAmountInCents;

        // 转换红包金额回元
        return array_map(function ($amountInCents) {
            return round($amountInCents / 100, 2); // 转换为元，保留两位小数
        }, $packets);
    }
}
