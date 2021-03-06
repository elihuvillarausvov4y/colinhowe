<?php

namespace app\api\controller;

use app\api\BaseController;

class Index extends BaseController
{
    public function index()
    {
        return jok(0, [
            'success' => 0,
            'data' => json_decode(file_get_contents('https://h5.oschina.net/apiv3/projectRecommend?size=50&page=1'), true)['data']['items']
        ]);
    }
    public function detail()
    {
        if (!input('id')) {
            return jerr('参数错误');
        }
        $id = intval(input('id'));
        return jok("Hello World!", json_decode(file_get_contents('https://h5.oschina.net/apiv3/projectDetail?id=' . $id), true)['data']['project']);
    }
}
