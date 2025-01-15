<?php

namespace App\Http\Controllers;

use App\Enums\ApiCodeEnum;
use App\Exceptions\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\UserService;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{

    private UserService $userService;

    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
    }

    /**
     * 用户注册
     * @param Request $request
     * @return JsonResponse
     * @throws BusinessException
     * @throws ValidationException
     * @author yjf
     * @date 2024-05-10 11:44
     */
    public function register(Request $request): JsonResponse
    {
        $this->validate($request, [
            'wechat' => 'required|min:4|max:20',
            'nickname' => 'required|max:20',
            'mobile' => 'required|regex:/^1[3-9]\d{9}$/',
            'password' => 'required|min:6',
        ]);
        $data = $this->userService->register($this->params);
        return $this->success($data, $request, ApiCodeEnum::SERVICE_REGISTER_SUCCESS);
    }

    /**
     * 用户登录
     * @param Request $request
     * @return JsonResponse
     * @throws BusinessException
     * @throws ValidationException
     * @author yjf
     * @date 2024-05-10 11:44
     */
    public function login(Request $request): JsonResponse
    {
        $this->validate($request, [
            'mobile' => 'required|regex:/^1[3-9]\d{9}$/',
            'password' => 'required|min:6',
            'code' => 'required|digits:4|int'
        ]);

        $data = $this->userService->login($this->params);
        return $this->success($data, $request, ApiCodeEnum::SERVICE_LOGIN_SUCCESS);
    }

    /**
     * 用户注销
     * @param Request $request
     * @return JsonResponse
     * @author yjf
     * @date 2024-05-10 11:45
     */
    public function logout(Request $request): JsonResponse
    {
        $this->userService->logout($request->user()->id);
        return $this->success([], $request);
    }

    /**
     * 用户主页
     * @param string $keywords
     * @param Request $request
     * @return JsonResponse
     * @throws BusinessException
     * @author yjf
     * @date 2024-05-10 11:33
     */
    public function home(string $keywords, Request $request): JsonResponse
    {
        $this->params['keywords'] = $keywords;
        $data = $this->userService->home($this->params);
        return $this->success($data, $request);
    }

    public function info(int $id, Request $request): JsonResponse
    {
        $this->params['id'] = $id;
        $data = $this->userService->info($this->params);
        return $this->success($data, $request);
    }

    /**
     * 我
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $data = $this->userService->update($this->params);
        return $this->success($data, $request);
    }

    /**
     * @throws ValidationException
     */
    public function moments(Request $request): JsonResponse
    {
        $this->validate($request, [
            'user_id' => 'required',
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:20',
        ]);
        $data = $this->userService->moments($this->params);
        return $this->setPageInfo($data[0])->success($data[1], $request);
    }

    /**
     * 充值
     * @throws ValidationException
     * @throws BusinessException
     */
    public function charge(Request $request): JsonResponse
    {
        $this->validate($request, [
            'money' => 'required|integer|min:1|max:10000'
        ]);
        $data = $this->userService->charge($this->params);
        return $this->success($data, $request);
    }
}
