<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    private MessageService $messageService;

    public function __construct()
    {
        parent::__construct();
        $this->messageService = new MessageService();
    }

    /**
     * 聊天消息列表
     * @throws ValidationException|BusinessException
     */
    public function list(Request $request): JsonResponse
    {
        $this->validate($request, [
            'to_user' => 'required',
            'is_group' => 'required'
        ]);
        $data = $this->messageService->list($this->params);
        return $this->success($data, $request);
    }

    /**
     * 发送消息
     * @throws BusinessException
     * @throws ValidationException
     */
    public function send(Request $request): JsonResponse
    {
        $this->validate($request, [
            'to_user' => 'required',
            'type' => 'required',
            'is_group' => 'required'
        ]);
        $data = $this->messageService->send($this->params);
        return $this->success($data, $request);
    }

    /**
     * 聊天消息已读
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function read(Request $request): JsonResponse
    {
        $this->validate($request, [
            'to_user' => 'required',
            'is_group' => 'required'
        ]);
        $this->messageService->read($this->params);
        return $this->success([], $request);
    }

    /**
     * 聊天消息撤回
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException|BusinessException
     */
    public function undo(Request $request): JsonResponse
    {
        $this->validate($request, [
            'id' => 'required'
        ]);
        $this->messageService->undo($this->params);
        return $this->success([], $request);
    }

    /**
     * 未读聊天消息
     * @param Request $request
     * @return JsonResponse
     */
    public function unread(Request $request): JsonResponse
    {
        $data = $this->messageService->unread($request->user()->id);
        return $this->success($data, $request);
    }
}
