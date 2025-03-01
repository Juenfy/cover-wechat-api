<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FileController extends Controller
{
    private FileService $fileService;

    public function __construct()
    {
        parent::__construct();
        $this->fileService = new FileService();
    }

    /**
     * 上传文件
     * @param Request $request
     * @return JsonResponse
     * @throws BusinessException
     * @throws ValidationException
     */
    public function upload(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->validate($request, [
            'file' => 'required|file',
        ]);
        $data = $this->fileService->upload($request);
        return $this->success($data, $request);
    }

    /**
     * 上传文件base64
     * @param Request $request
     * @return JsonResponse
     * @throws BusinessException
     * @throws ValidationException
     */
    public function uploadBase64(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->validate($request, [
            'base64' => 'required'
        ]);
        $data = $this->fileService->uploadBase64($this->params['base64']);
        return $this->success($data, $request);
    }
}
