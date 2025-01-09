<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

//系统模块
$router->group(['prefix' => 'system'], function ($router) {
    $router->post('/captcha/get', 'SystemController@get');
    $router->post('/captcha/check', 'SystemController@check');
    $router->post('/captcha/verification', 'SystemController@verification');
});

//文件模块
$router->group(['prefix' => 'file'], function ($router) {
    $router->post('/upload', 'FileController@upload');
    $router->post('/upload-base64', 'FileController@uploadBase64');
});

//用户模块-需校验验证码
$router->group(['prefix' => 'user', 'middleware' => 'captcha'], function ($router) {
    $router->post('/register', 'UserController@register');
    $router->post('/login', 'UserController@login');
});

//需要鉴权
$router->group(['middleware' => 'auth:api'], function ($router) {
    //用户模块
    $router->group(['prefix' => 'user'], function ($router) {
        $router->post('/logout', 'UserController@logout');
        $router->get('/me', 'UserController@me');
        $router->get('/info', 'UserController@info');
        $router->get('/{keywords}/home', 'UserController@home');
        $router->put('/update', 'UserController@update');
        $router->get('/moments', 'UserController@moments');
        $router->post('/charge', 'UserController@charge');
    });

    // 消息模块
    $router->group(['prefix' => 'chat'], function ($router) {
        $router->get('/list', 'ChatController@list');
        $router->get('/info', 'ChatController@info');
        $router->put('/top', 'ChatController@top');
        $router->put('/hide', 'ChatController@hide');
        $router->put('/delete', 'ChatController@delete');
        $router->put('/update', 'ChatController@update');
    });

    $router->group(['middleware' => 'message', 'prefix' => 'message'], function ($router) {
        $router->post('/send', 'MessageController@send');
        $router->put('/read', 'MessageController@read');
    });
    $router->get('message/list', 'MessageController@list');
    $router->get('message/unread', 'MessageController@unread');
    $router->put('message/undo', 'MessageController@undo');

    //群组模块
    $router->group(['prefix' => 'group'], function ($router) {
        $router->post('/create', 'GroupController@create');
        $router->post('/action', 'GroupController@action');
        $router->get('/list', 'GroupController@list');
    });

    //好友模块
    $router->group(['prefix' => 'friend'], function ($router) {
        $router->get('/list', 'FriendController@list');
        $router->get('/apply-list', 'FriendController@applyList');
        $router->delete('/delete-apply/{id}', 'FriendController@deleteApply');
        $router->get('/search/{keywords}', 'FriendController@search');
        $router->post('/show-confirm', 'FriendController@showConfirm');
        $router->post('/apply', 'FriendController@apply');
        $router->post('/verify', 'FriendController@verify');
        $router->put('/update', 'FriendController@update');
    });

    //朋友圈模块
    $router->group(['prefix' => 'moment'], function ($router) {
        $router->get('/list', 'MomentController@list');
        $router->get('/detail', 'MomentController@detail');
        $router->get('/message', 'MomentController@message');
        $router->post('/publish', 'MomentController@publish');
        $router->post('/like', 'MomentController@like');
        $router->delete('/unlike', 'MomentController@unlike');
        $router->post('/comment', 'MomentController@comment');
        $router->delete('/delete', 'MomentController@delete');
    });

    //红包模块
    $router->group(['prefix' => 'red-packet'], function ($router) {
        $router->get('/record-list', 'RedPacketController@recordList');
        $router->get('/detail', 'RedPacketController@detail');
        $router->post('/send', 'RedPacketController@send');
        $router->post('/receive', 'RedPacketController@receive');
    });
});
