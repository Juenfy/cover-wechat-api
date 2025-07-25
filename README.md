# <center>仿微信服务端接口</center>

<a href="https://2e9123d0d080.ngrok-free.app/" target="_blank">在线体验</a>
测试账号：13006789001 ~ 13006789010 密码：123456
或者自行注册

客户端传送门：https://github.com/Juenfy/cover-wechat-client
### 项目介绍
技术栈：lumen+workerman
目前实现的功能：
1. 注册登录
2. 好友查找、申请、添加
3. 私聊、群聊（目前支持发送文本、表情、图片、视频）
4. 消息通知
5. 扫码加好友
6. 个人信息
7. 拍照更换头像、选择相册更换头像
8. 朋友圈
9. 更新私聊语音视频通话功能（WeRTC实现的） 
10. 等等其他小功能
会一直持续更新！！！

### 项目运行

导入数据库cover_wechat.sql
改好env配置

```shell
git clone https://github.com/Juenfy/cover-wechat-api.git
```

```shell
cd cover-wechat-backend
```

```shell
cp .env.example .env
```

```shell
composer install
```

启动workerman服务

windows直接点击运行start_for_win.bat

linux运行
```shell
php artisan workerman start --start=all --d
```

运行项目
```shell
php -S localhost:8000 -t public
```

### 项目预览
聊天列表：
<img src="./public/preview/chat.jpg" alt="wechat" style="width: 30%;">

聊天详情：
<img src="./public/preview/chat-message.jpg" alt="wechat" style="width: 30%;">

通讯录：
<img src="./public/preview/friend.jpg" alt="wechat" style="width: 30%;">

好好享受吧！！！
