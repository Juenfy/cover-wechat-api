<?php

namespace App\Services;

use App\Enums\ApiCodeEnum;
use App\Enums\Database\FileEnum;
use App\Exceptions\BusinessException;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class FileService extends BaseService
{
    /**
     * 上传文件
     * @param Request $request
     * @return array
     * @throws BusinessException
     */
    public function upload(Request $request): array
    {
        //上传的如果是头像就需要裁剪成正方头像
        $avatar = $request->input('avatar');
        $file = $request->file('file');
        return $this->uploadFile($file, $avatar);
    }

    /**
     * 上传文件
     * @param UploadedFile $file
     * @param mixed $avatar
     * @return bool|array
     * @throws BusinessException
     */
    public function uploadFile(UploadedFile $file, mixed $avatar): bool|array
    {
        if (!$file->isValid()) {
            $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
        }
        // 已存在直接返回
        $tmpPath = $file->getPathname();
        $signature = md5_file($tmpPath);
        if ($data = $this->checkFileExists($signature)) {
            return $data;
        }
        // 生成新的文件名
        $extension = $file->getClientOriginalExtension();
        $newFileName = $signature . '.' . $extension;
        $newThumbnailFileName = 'thumbnail_' . $newFileName;
        // 获取文件信息
        $size = $file->getSize();
        $mimeTypeArr = explode('/', $file->getClientMimeType());
        $fileType = $mimeTypeArr[0] ?? 'file';
        if (!in_array($fileType, FileEnum::TYPE)) {
            $fileType = 'file';
        }
        $this->limitFileSIze($fileType, $size);
        // 初始化变量
        $width = 0;
        $height = 0;
        $duration = 0;
        $date = date('Ymd');
        $fileDir = "uploads/{$fileType}/{$date}";
        $filePath = "{$fileDir}/{$newFileName}";
        $outputPath = Storage::disk('public')->path($filePath);
        $thumbnailFilePath = '';
        $needCropSquare = false;
        //头像的话需要截图
        if ($avatar) {
            $needCropSquare = $this->cropSquare($tmpPath, $outputPath);
        }
        // 指定上传路径并重命名文件
        if (!$needCropSquare)
            $filePath = $file->storeAs("{$fileDir}", $newFileName, 'public');
        else {
            $tmpPath = $outputPath;
        }
        if (!$filePath) {
            $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, '文件上传失败！');
        }


        $configuration = [];
        if (is_linux()) {
            $configuration = [
                'ffmpeg.binaries' => env('FFMPEG_PATH'),
                'ffprobe.binaries' => env('FFPROBE_PATH')
            ];
        }
        $ffmpeg = \FFMpeg\FFMpeg::create($configuration);
        $ffprobe = \FFMpeg\FFProbe::create($configuration);

        if ($fileType == FileEnum::IMAGE) {
            // 生成缩略图
            $thumbnailFilePath = "{$fileDir}/{$newThumbnailFileName}";
            list($width, $height, $size) = $this->makeThumbnailImage($tmpPath, $thumbnailFilePath);
        } elseif ($fileType == FileEnum::VIDEO) {
            // 获取视频时长和封面图
            $video = $ffmpeg->open($tmpPath);
            $duration = (int)$ffprobe->format($tmpPath)->get('duration');
            $width = (int)$ffprobe->format($tmpPath)->get('width');
            $height = (int)$ffprobe->format($tmpPath)->get('height');
            $newThumbnailFileName = str_replace($extension, 'jpg', $newThumbnailFileName);
            $thumbnailFilePath = "{$fileDir}/{$newThumbnailFileName}";
            $video->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(1))
                ->save(storage_path('app/public/' . $thumbnailFilePath));
        } elseif ($fileType == FileEnum::AUDIO) {
            // 获取音频时长 音频没声音会获取失败的
            $duration = (int)$ffprobe->format($tmpPath)->get('duration');
            if (!$duration && request('duration')) {
                $duration = request('duration');
            }
        }

        // 将文件信息存储到数据库
        $fileRecord = new File();
        $fileRecord->name = $newFileName;
        $fileRecord->path = $filePath;
        $fileRecord->thumbnail_path = $thumbnailFilePath;
        $fileRecord->size = $size;
        $fileRecord->width = $width;
        $fileRecord->height = $height;
        $fileRecord->duration = $duration;
        $fileRecord->signature = $signature;
        $fileRecord->type = $fileType;
        $fileRecord->format = $extension;
        $fileRecord->save();
        $fileRecord->path = $filePath;
        $fileRecord->thumbnail_path = $thumbnailFilePath;
        // 获取文件的 URL
        $data = $fileRecord->toArray();
        $data['url'] = env('STATIC_FILE_URL') . $filePath;
        $data['thumbnail_url'] = env('STATIC_FILE_URL') . $thumbnailFilePath;

        return $data;
    }

    /**
     * 上传文件base64
     * @param string $base64Image
     * @return bool|array
     * @throws BusinessException
     */
    public function uploadBase64(string $base64Image): bool|array
    {
        // 解析 Base64 编码的图像数据
        list($type, $data) = explode(';', $base64Image);
        list(, $data) = explode(',', $data);
        $imageData = base64_decode($data);
        $date = date('Ymd');
        // 确定文件扩展名
        $mimeType = explode(':', explode(';', $base64Image)[0])[1];
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            default => $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR),
        };
        // 生成唯一文件名
        $fileName = uniqid() . '.' . $extension;
        $fileDir = "uploads/image/{$date}";
        $filePath = "$fileDir/{$fileName}";
        Storage::disk('public')->put($filePath, $imageData);
        $fileRealPath = Storage::disk('public')->path($filePath);
        $signature = md5_file($fileRealPath);
        if ($data = $this->checkFileExists($signature)) {
            @unlink($fileRealPath);
            return $data;
        }
        $size = Storage::disk('public')->size($filePath);
        // 生成缩略图
        $thumbnailFilePath = "$fileDir/thumbnail_{$fileName}";
        list($width, $height) = $this->makeThumbnailImage($fileRealPath, $thumbnailFilePath);
        // 将文件信息存储到数据库
        $fileRecord = new File();
        $fileRecord->name = $fileName;
        $fileRecord->path = $filePath;
        $fileRecord->thumbnail_path = $thumbnailFilePath;
        $fileRecord->size = $size;
        $fileRecord->width = $width;
        $fileRecord->height = $height;
        $fileRecord->duration = 0;
        $fileRecord->signature = $signature;
        $fileRecord->type = 'image';
        $fileRecord->format = $extension;
        $fileRecord->save();
        $fileRecord->path = $filePath;
        $fileRecord->thumbnail_path = $thumbnailFilePath;
        // 获取文件的 URL
        $data = $fileRecord->toArray();
        $data['url'] = env('STATIC_FILE_URL') . $filePath;
        $data['thumbnail_url'] = env('STATIC_FILE_URL') . $thumbnailFilePath;

        return $data;
    }

    /**
     * 文件是否存在
     * @param string $signature
     * @return bool|array
     */
    private function checkFileExists(string $signature): bool|array
    {
        $file = File::query()->where('signature', $signature)->first();
        if ($file) {
            $data = $file->toArray();
            $data['url'] = $file->path;
            $data['thumbnail_url'] = $file->thumbnail_path;
            return $data;
        }
        return false;
    }

    /**
     * 生成缩略图
     * @param string $filePath
     * @param string $thumbnailFilePath
     * @return array
     * @throws BusinessException
     */
    public function makeThumbnailImage(string $filePath, string $thumbnailFilePath): array
    {
        if (!file_exists($filePath)) $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, '生成缩略图失败，文件不存在');
        $image = Image::make($filePath);
        $width = $image->width();
        $height = $image->height();
        $size = $image->filesize();
        // 生成缩略图
        $thumbnail = $image->resize($width * 0.5, $height * 0.5, function ($constraint) {
            $constraint->aspectRatio();
        });
        Storage::disk('public')->put($thumbnailFilePath, (string)$thumbnail->encode());
        return [$width, $height, $size];
    }

    /**
     * 裁剪成正方形
     * @param string $filePath
     * @param string $outputPath
     * @return bool
     * @throws BusinessException
     */
    private function cropSquare(string $filePath, string $outputPath): bool
    {
        if (!file_exists($filePath)) $this->throwBusinessException(ApiCodeEnum::SYSTEM_ERROR, '裁剪图片失败，文件不存在');
        // 创建一个 Image 实例
        $image = Image::make($filePath);
        // 获取图片的宽和高
        $width = $image->width();
        $height = $image->height();
        if ($width == $height) return false;
        // 计算正方形的边长
        $sideLength = min($width, $height);
        // 计算裁剪位置
        $x = $width > $height ? intval(($width - $sideLength) / 2) : 0;
        $y = $height > $width ? intval(($height - $sideLength) / 2) : 0;
        // 居中裁剪成正方形
        $image->crop($sideLength, $sideLength, $x, $y);
        // 保存裁剪后的图片
        $image->save($outputPath);
        return true;
    }

    /**
     * 限制上传文件大小
     * @param string $fileType
     * @param int $size
     * @throws BusinessException
     */
    private function limitFileSIze(string $fileType, int $size): void
    {
        switch ($fileType) {
            case FileEnum::IMAGE:
                if ($size > FileEnum::IMAGE_LIMIT_SIZE) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
                break;
            case FileEnum::VIDEO:
                if ($size > FileEnum::VIDEO_LIMIT_SIZE) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
                break;
            case FileEnum::AUDIO:
                if ($size > FileEnum::AUDIO_LIMIT_SIZE) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
                break;
            default:
                if ($size > FileEnum::FILE_LIMIT_SIZE) $this->throwBusinessException(ApiCodeEnum::CLIENT_PARAMETER_ERROR);
                break;
        }
    }
}
