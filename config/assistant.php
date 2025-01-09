<?php

/**
 * ai智能助手
 */

return [
    //key对应的用户表的id
    997 => [
        'platform' => 'cloudflare-ai',
        'wechat' => 'draw-image',
        'nickname' => 'AI绘图-小图',
        'type' => 'image',
        'avatar' => 'https://apic.tvzhe.com/images/e5/0b/71e2590f3550351caf86befb58a7140bf70e0be5.png',
        'account_id' => '970edfbecb39ea3bae2cc34ac1636ed4',
        'api_uri' => 'https://api.cloudflare.com/client/v4/accounts/970edfbecb39ea3bae2cc34ac1636ed4/ai/run/@cf/lykon/dreamshaper-8-lcm',
        'token' => 'QGb4agQuPlOgNS6VtzryfUI4xMce3N2Am284OVX2',
        'token_type' => 'Bearer',
        'messages' => [],
        'desc' => '我是AI绘图助手，我可以根据你的描述生成你喜欢的图片，前提是合法！',
        'history' => true, //是否需要重跑历史数据
    ],
    998 => [
        'platform' => 'cloudflare-ai',
        'wechat' => 'Firstlover',
        'nickname' => '小助手-小初恋',
        'type' => 'text',
        'avatar' => 'https://img2.baidu.com/it/u=2922666324,3508300190&fm=253&fmt=auto&app=138&f=JPEG',
        'account_id' => '970edfbecb39ea3bae2cc34ac1636ed4',
        'api_uri' => 'https://api.cloudflare.com/client/v4/accounts/970edfbecb39ea3bae2cc34ac1636ed4/ai/run/@cf/meta/llama-3-8b-instruct-awq',
        'token' => 'QGb4agQuPlOgNS6VtzryfUI4xMce3N2Am284OVX2',
        'token_type' => 'Bearer',
        'messages' => [['role' => 'system', 'content' => '我需要你扮演一个有趣、浪漫的小家伙，能够给别人带来欢乐的气氛，最好能够提供初恋的感觉并用中文回复用户！']],
        'desc' => '我是生活小助手，我叫小初恋，无聊可以找我解闷喔！',
        'history' => false, //是否需要重跑历史数据
    ],
    999 => [
        'platform' => 'cloudflare-ai',
        'wechat' => 'Shaforina',
        'nickname' => '小助手-莎菲娜',
        'type' => 'text',
        'avatar' => 'https://lf-flow-web-cdn.doubao.com/obj/flow-doubao/doubao/web/static/image/logo-icon-white-bg.f3acc228.png',
        'account_id' => '970edfbecb39ea3bae2cc34ac1636ed4',
        'api_uri' => 'https://api.cloudflare.com/client/v4/accounts/970edfbecb39ea3bae2cc34ac1636ed4/ai/run/@cf/meta/llama-3-8b-instruct',
        'token' => 'QGb4agQuPlOgNS6VtzryfUI4xMce3N2Am284OVX2',
        'token_type' => 'Bearer',
        'messages' => [['role' => 'system', 'content' => '我需要你扮演一个百科全书、富有幽默感、人情世故的智能小助手，懂得处于用户的角度去分析并用中文回复用户！']],
        'desc' => '我是生活小助手，我叫萨菲娜，有什么问题可以问我喔！',
        'history' => false,
    ]
];
