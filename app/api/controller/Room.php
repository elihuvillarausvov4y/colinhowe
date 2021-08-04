<?php

namespace app\api\controller;

use app\api\BaseController;
use app\model\Room as RoomModel;
use think\App;

class Room extends BaseController
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        //查询列表时允许的字段
        $this->selectList = "*";
        //查询详情时允许的字段
        $this->selectDetail = "*";
        //筛选字段
        $this->searchFilter = [
            "room_id" => "=",
            "room_user" => "like", "room_name" => "like", "room_type" => "like", "room_password" => "like", "room_notice" => "like",
        ];
        $this->insertFields = [
            //允许添加的字段列表
            "room_user", "room_name", "room_type", "room_password", "room_notice",
        ];
        $this->updateFields = [
            //允许更新的字段列表
            "room_user", "room_name", "room_type", "room_password", "room_notice", "room_robot", "room_addsong",
             "room_sendmsg", "room_public", "room_playone", "room_domain","room_huya","room_votepass","room_votepercent",
             "room_addsongcd","room_pushdaycount","room_pushsongcd","room_addcount"
        ];
        $this->insertRequire = [
            //添加时必须填写的字段
            // "字段名称"=>"该字段不能为空"
            "room_name" => "房间名称必须填写呀",

        ];
        $this->updateRequire = [
            //修改时必须填写的字段
            // "字段名称"=>"该字段不能为空"
            "room_name" => "房间名称必须填写呀",

        ];
        $this->model = new RoomModel();
    }
    public function getRoomByDomain()
    {
        if (!input('room_domain')) {
            return jerr('room_domain缺失');
        }
        $room_domain = input('room_domain');
        if($room_domain=='bbbug.com'){
            $room_domain = 'www';
        }
        $room = $this->model->where('room_domain', $room_domain)->where('room_domainstatus', 1)->find();
        if ($room) {
            unset($room['room_password']);
            return jok('success', $room);
        } else {
            $cname = dns_get_record($room_domain, DNS_CNAME);
            if (count($cname) > 0) {
                $subDomain = str_replace('.bbbug.com', '', $cname[0]['target']);
                if ($subDomain != $cname[0]['target']) {
                    $room = $this->model->where('room_domain', $subDomain)->where('room_domainstatus', 1)->find();
                    if ($room) {
                        unset($room['room_password']);
                        return jok('success', $room);
                    }
                }
            } 
            return jerr('没有查询到域名绑定的房间，即将进入大厅');
        }
    }
    public function saveMyRoom()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "参数必须填写", 400);
        }
        //根据主键获取一行数据
        $item = $this->getRowByPk();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        if ($item['room_user'] != $this->user['user_id'] && !getIsAdmin($this->user)) {
            return jerr("你没有权限修改此房间信息");
        }
        //校验Update字段是否填写
        $error = $this->validateUpdateFields();
        if ($error) {
            return $error;
        }
        //从请求中获取Update数据
        $data = $this->getUpdateDataFromRequest();
        //根据主键更新这条数据

        if ($data['room_public'] == 0) {
            //设置公开 取消密码
            $data['room_password'] = '';
        } else {
            //设置加密
            if (empty($item['room_password'])) {
                //原来也没设置密码
                if (empty($data['room_password'])) {
                    return jerr('请输入一个房间密码');
                }
            } else {
                //原来设置了密码 如果不输入 则不修改
                if (empty($data['room_password'])) {
                    unset($data['room_password']);
                }
            }
        }

        if(empty($data['room_type']) || !in_array($data['room_type'],[0,1,4])){
            $data['room_type'] = 1;
        }

        if ($data['room_domain']) {
            $exist = $this->model->where('room_id', 'not like', $this->pk_value)->where('room_domain', $data['room_domain'])->where('room_domainstatus', 1)->find();
            if ($exist) {
                return jerr($data['room_domain'] . '.bbbug.com已被其他人使用啦,换一个再试试吧!');
            }
        } else {
            $data['room_domain'] = '';
        }

        
        if(!empty(input('room_addsongcd')) && intval(input('room_addsongcd')) < 60 && intval(input('room_addsongcd')) > 0){
            $data['room_addsongcd'] = intval($data['room_addsongcd']);
        }else{
            $data['room_addsongcd'] = 60;
        }
        
        if(!empty(input('room_pushsongcd'))  && intval(input('room_pushsongcd')) > 0){
            $data['room_pushsongcd'] = intval($data['room_pushsongcd']);
        }else{
            $data['room_pushsongcd'] = 60;
        }
        
        if(!empty(input('room_pushdaycount')) && intval(input('room_pushdaycount')) > 0){
            $data['room_pushdaycount'] = intval($data['room_pushdaycount']);
        }else{
            $data['room_pushdaycount'] = 5;
        }

        if(!empty(input('room_addcount')) && intval(input('room_addcount')) > 0){
            $data['room_addcount'] = intval($data['room_addcount']);
        }else{
            $data['room_addcount'] = 5;
        }


        $this->updateByPk($data);
        if ($data['room_type'] != 1) {
            cache('SongNow_' . $this->pk_value, null);
        }
        $msg = [
            'type' => 'roomUpdate',
            'user' => getUserData($this->user),
        ];

        curlHelper(getWebsocketApiUrl(), "POST", http_build_query([
            'type' => 'channel',
            'to' => $this->pk_value,
            'token' => getWebsocketToken(),
            'msg' => json_encode($msg),
        ]), [
            'content-type:application/x-www-form-rawurlencode',
        ]);

        return jok('房间信息修改成功');
    }
    public function create()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        // return jerr('创建房间功能暂时关闭');
        //校验Insert字段是否填写
        $error = $this->validateInsertFields();
        if ($error) {
            return $error;
        }

        $myRoom = $this->model->where('room_user', $this->user['user_id'])->find();
        if ($myRoom) {
            return jerr('创建失败,你已经有了一个房间');
        }
        //从请求中获取Insert数据
        $data = $this->getInsertDataFromRequest();
        //添加这行数据
        $data['room_user'] = $this->user['user_id'];
        // if ($data['room_password']) {
        //     $data['room_public'] = 1;
        // } else {
        //     $data['room_public'] = 0;
        // }
        $room_id = $this->insertRow($data);
        return jok('你的私人房间创建成功!',[
            'room_id'=>$room_id
        ]);
    }
    public function getWebsocketUrl()
    {
        if (input('access_token') == getTempToken()) {$channel = input('channel');
            $user_id = 0 - rand(10000000, 99999999);
            $lastSend = cache('channel_' . $channel . '_user_' . $user_id) ?? false;
            if (!$lastSend) {
                $ipLogin = cache('channel_'.$channel.'_ip_'.getClientIp()) ?? false;
                if($ipLogin){
                    $msg = [
                        'type' => 'join',
                        'content' => "临时用户 " . $user_id . " 进入房间",
                    ];
                    if(input("plat") == "vscode"){
                        $msg = [
                            'type' => 'system',
                            'content' => "vscode插件临时用户 " . $user_id . " 进入房间",
                        ];
                    }

                    curlHelper(getWebsocketApiUrl(), "POST", http_build_query([
                        'type' => 'channel',
                        'to' => $channel,
                        'token' => getWebsocketToken(),
                        'msg' => json_encode($msg),
                    ]), [
                        'content-type:application/x-www-form-rawurlencode',
                    ]);
                    cache('channel_' . $channel . '_user_' . $user_id, time(), 10);
                    cache('channel_'.$channel.'_ip_'.getClientIp(),time(),10);
                }else{
                    // $msg = [
                    //     'type' => 'system',
                    //     'content' => "IP " . getClientIp() . " 正在刷新页面",
                    // ];

                    // curlHelper(getWebsocketApiUrl(), "POST", http_build_query([
                    //     'type' => 'channel',
                    //     'to' => $channel,
                    //     'token' => getWebsocketToken(),
                    //     'msg' => json_encode($msg),
                    // ]), [
                    //     'content-type:application/x-www-form-rawurlencode',
                    // ]);
                    // return jerr('刷新太过频繁,请稍后再试!');
                }
            }

            return jok('success', [
                'account' => $user_id,
                'channel' => $channel,
                'ticket' => sha1("account" . $user_id . "channel" . $channel . 'salt' . $channel),
            ]);
        }
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!input('channel')) {
            return jerr("请选择一个房间呀");
        }
        $channel = input('channel');

        $item = $this->model->where('room_id', $channel)->find();
        if (!$item) {
            return jerr('没有查询到房间信息');
        }

        if ($item['room_public'] == 1 && $this->user['user_id'] != $item['room_user'] && !getIsAdmin($this->user)) {
            $savedPassword = cache('password_room_' . $item['room_id'] . "_password_" . $this->user['user_id']) ?? '';
            $inputPassword = input('room_password');
            if ($item['room_password'] != $savedPassword && $item['room_password'] != $inputPassword) {
                return jerr("房间密码错误，获取服务器地址失败");
            }
        }

        $lastSend = cache('channel_' . $channel . '_user_' . $this->user['user_id']) ?? false;
        if (!$lastSend) {
            $msg = [
                'type' => 'join',
                'content' => "用户 " . rawurldecode($this->user['user_name']) . " 进入房间",
            ];

            if ($this->user['user_id'] > 1) {
                curlHelper(getWebsocketApiUrl(), "POST", http_build_query([
                    'type' => 'channel',
                    'to' => $channel,
                    'token' => getWebsocketToken(),
                    'msg' => json_encode($msg),
                ]), [
                    'content-type:application/x-www-form-rawurlencode',
                ]);
            }
            cache('channel_' . $channel . '_user_' . $this->user['user_id'], time(), 10);
        }

        return jok('success', [
            'account' => $this->user['user_id'],
            'channel' => $channel,
            'ticket' => sha1("account" . $this->user['user_id'] . "channel" . $channel . 'salt' . $channel),
        ]);
    }
    public function hotRooms()
    {
        if (input('access_token') == getTempToken()) {
            $order = 'room_order desc,room_online desc,room_score desc,room_id desc';
            //设置Model中的 per_page
            $this->setGetListPerPage();
            $dataList = $this->model->getHotRooms($order, $this->selectList);
            for ($i = 0; $i < count($dataList); $i++) {
                if (in_array($dataList[$i]['user_group'], [1])) {
                    $dataList[$i]['user_admin'] = true;
                } else {
                    $dataList[$i]['user_admin'] = false;
                }
                unset($dataList[$i]['user_group']);
                unset($dataList[$i]['room_password']);
            }
            return jok('数据获取成功', $dataList);
        }
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $order = 'room_order desc,room_online desc,room_score desc,room_id desc';
        //设置Model中的 per_page
        $this->setGetListPerPage();
        $dataList = $this->model->getHotRooms($order, $this->selectList);
        for ($i = 0; $i < count($dataList); $i++) {
            if (in_array($dataList[$i]['user_group'], [1])) {
                $dataList[$i]['user_admin'] = true;
            } else {
                $dataList[$i]['user_admin'] = false;
            }
            unset($dataList[$i]['room_password']);
        }
        return jok('数据获取成功', $dataList);
    }
    public function myRoom()
    {
        if (input('access_token') == getTempToken()) {
            return jerr('请登录后体验完整功能!', 401);
        }
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $myRoom = $this->model->where('room_user', $this->user['user_id'])->find();
        unset($myRoom['room_password']);
        return jok('获取成功', $myRoom);
    }
    public function getRoomInfo()
    {
        if($this->pk_value!=888 && $this->pk_value!=10028){
            // return jerr("子房间维护中,维护恢复时间预计2小时,请稍后再进入子房间！");
        }
        if (input('access_token') == getTempToken()) {
            if (!$this->pk_value) {
                return jerr($this->pk . "必须填写", 400);
            }
            //根据主键获取一行数据
            $item = $this->getRowByPk();
            if (empty($item)) {
                return jerr("没有查询到数据", 404);
            }
            if ($item['room_public'] == 1) {
                return jerr("暂不支持游客进入密码房间");
            }
            unset($item['room_password']);
            if ($item['room_status'] == 1) {
                return jerr($item['room_reason'],301);
            }
            return jok('数据加载成功', $item);
        }
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "必须填写", 400);
        }
        //根据主键获取一行数据
        $item = $this->getRowByPk();
        if (empty($item)) {
            return jerr("没有查询到数据", 404);
        }
        if ($item['room_public'] == 1 && $this->user['user_id'] != $item['room_user'] && !getIsAdmin($this->user)) {
            $savedPassword = cache('password_room_' . $item['room_id'] . "_password_" . $this->user['user_id']) ?? '';
            $inputPassword = input('room_password');
            if ($item['room_password'] != $savedPassword && $item['room_password'] != $inputPassword) {
                cache('password_room_' . $item['room_id'] . "_password_" . $this->user['user_id'],null);
                return jerr("房间密码错误，进入房间失败", 302);
            }
            cache('password_room_' . $item['room_id'] . "_password_" . $this->user['user_id'], $item['room_password'], 86400);
        }
        if ($item['room_status'] == 1) {
            return jerr($item['room_reason'],301);
        }
        unset($item['room_password']);
        return jok('数据加载成功', $item);
    }
}
