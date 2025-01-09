<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Services\RedPacketService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RedPacketController extends Controller
{
    private RedPacketService $redPacketService;

    public function __construct()
    {
        parent::__construct();

        $this->redPacketService = new RedPacketService();
    }

    /**
     * @throws ValidationException
     */
    public function recordList(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->validate($request, [
            'id' => 'integer|required'
        ]);
        $data = $this->redPacketService->recordList($this->params);
        return $this->setPageInfo($data[0])->success($data[1], $request);
    }

    /**
     * 发红包
     * @throws BusinessException
     * @throws ValidationException
     */
    public function send(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->validate($request, [
            'type' => 'string|required',
            'total' => 'integer|required|min:1',
            'money' => 'required|min:0.01'
        ]);
        $data = $this->redPacketService->send($this->params);
        return $this->success($data, $request);
    }

    /**
     * 红包详情
     * @throws ValidationException
     */
    public function detail(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->validate($request, [
            'id' => 'integer|required',
        ]);
        $data = $this->redPacketService->detail($this->params);
        return $this->success($data, $request);
    }

    /**
     * 抢红包
     * @throws ValidationException
     */
    public function receive(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->validate($request, [
            'id' => 'integer|required',
        ]);
        $data = $this->redPacketService->receive($this->params);
        return $this->success($data, $request);
    }
}
