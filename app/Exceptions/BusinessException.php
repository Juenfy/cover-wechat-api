<?php

namespace App\Exceptions;

use Exception;

class BusinessException extends Exception
{
    /**
     * 业务异常构造函数
     * @param string|int $code 状态码
     * @param string $message 自定义返回信息
     */
    public function __construct(string|int $code, $message = '')
    {
        if (stripos($code, '|') !== false) {
            $arr = explode('|', $code);
            empty($message) && $message = $arr[1];
            $code = $arr[0];
        }
        empty($message) && $message = '未知错误';
        parent::__construct($message, (int)$code);
    }
}
