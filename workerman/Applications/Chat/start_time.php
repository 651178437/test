<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of time
 *
 * @author Administrator
 */

use \Workerman\Worker;
use \Workerman\Lib\Timer;

use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Db;
use \Workerman\Autoloader;

date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

$task = new Worker();
// 开启多少个进程运行定时任务，注意多进程并发问题
$task->count = 1;
$task->onWorkerStart = function($task)
{
    //900 秒  15分执行一次
    $time_interval = 900;
    Timer::add($time_interval, function()
    {
        echo 'timer';

        $db = Db::instance('db');
        $sql = "select roomid,status from live_liveroom where  status = 1 order by roomid asc";
        $roomlist = $db->query($sql);

        if(!empty($roomlist)){
            foreach ($roomlist as $key=>$val){
                $room_id = $val['roomid'];   //这个是房间的信息
				$clients_list = Gateway::getClientInfoByGroup($room_id);
				if(count($clients_list) == 0) continue;
                //获取在线总数
                $count = Gateway::getClientCountByGroup($room_id);
                $db->insert('live_people_count')->cols(array('people_count'=>$count ,'time'=>time(),'roomid'=>$room_id))->query();    
            }
        }
        
    });
};


if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
