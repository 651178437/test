<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose 
 */
use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Db;

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL&~E_NOTICE&~E_WARNING);
class Event
{
    
    
    /**
    * 有消息时
    * @param int $client_id
    * @param string $message
    */
   public static function onMessage($client_id, $message)
   {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";
        
        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return ;
        }
        
        // 根据类型执行不同的业务
        switch($message_data['type'])
        {
            // 客户端回应服务端的心跳
            case 'pong':
                return;
            // 客户端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'delete':
                //管理员踢人
                $room_id = $message_data['room_id'];
                $to_client_id = $message_data['to_client_id'];
                
                $clients_list = Gateway::getClientInfoByGroup($room_id);
              
                if(isset($clients_list[$to_client_id])){
                    $userid = $clients_list[$to_client_id]['userid'];
                    
                    $db = Db::instance('db');
                    
                    $userid = $db->select('userid')->from('live_userinfo_base')->where("userid='".$userid."'")->single();
                    //$db->delete('live_userinfo_base')->where("username='".$client_name."'")->query();
                    $new_message = array(
                        'type'=>'delete',
                        'from_client_id'=>$client_id, 
                        'from_client_name' =>'',
                        'to_client_id'=>$message_data['to_client_id'],
                        'content'=>"delete",
                        'time'=>date('H:i')
                    );
                    Gateway::sendToClient($message_data['to_client_id'], json_encode($new_message));    //发送给被踢人 ，提示已被踢
                    $new_message['type'] = 'say';
                    $new_message['from_client_name'] = $_SESSION['client_name'];
                    $new_message['content'] = "<b>你对".$client_name."踢出: 成功</b>";
                    return Gateway::sendToCurrentClient(json_encode($new_message));                     //  发送给管理员被踢人成功
                }
                return ;
            case 'login':
                // 判断是否有房间号
                if(!isset($message_data['room_id']))
                {
                    throw new \Exception("\$message_data['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                
                // 把房间号昵称放到session中
                $room_id = $message_data['room_id'];
                $client_name = htmlspecialchars($message_data['client_name']);
                $roleimg = filter_var($message_data['roleimg'],FILTER_SANITIZE_STRING);
                $userid = $message_data['userid'];
                $_SESSION['room_id'] = $room_id;
                $_SESSION['client_name'] = filter_var($client_name,FILTER_SANITIZE_STRING);
                $_SESSION['roleimg'] = $roleimg;
                $_SESSION['userid'] = $userid;

                // 获取房间内所有用户列表 
                $clients_list = Gateway::getClientInfoByGroup($room_id);
                
                foreach($clients_list as $tmp_client_id=>$item)
                {
                    $clients_list[$tmp_client_id]['client_name'] = $item['client_name'];
                    $clients_list[$tmp_client_id]['roleimg'] = $item['roleimg'];
                }
                
                $clients_list[$client_id]['client_name'] = $client_name;
                $clients_list[$client_id]['roleimg']     = $roleimg;
                $clients_list[$client_id]['userid']     = $userid;

                // 转播给当前房间的所有客户端，xx进入聊天室 message {type:login, client_id:xx, name:xx} 
                $new_message = array('type'=>$message_data['type'], 'userid' => $userid,'client_id'=>$client_id,'roleimg'=>$roleimg, 'client_name'=>htmlspecialchars($client_name), 'time'=>date('Y-m-d H:i:s'));
                
                Gateway::sendToGroup($room_id, json_encode($new_message));   //分发数据
                
                Gateway::joinGroup($client_id, $room_id);                    // 将client_id加入房间   
                
                $hour  = date("G");
                $today = date("Y-m-d");
                
                //获取在线总数
                $count = Gateway::getClientCountByGroup($room_id);
                
                $db = Db::instance('db');
                $sql = "select num,onlineid,adduser from live_online  where today='".$today."' and h = ".$hour." and roomid=".$room_id."  order by onlineid desc limit 1";   
                
                $info = $db->row($sql);

                //判断用户登录
                $time = strtotime(date('Y-m-d'));
                $time_end = $time + 86400;
                $sql = "SELECT * FROM live_room_log WHERE uid={$userid} AND roomid={$room_id} AND login_time >= $time AND login_time < $time_end";
                $loginfo = $db->row($sql);
                if(!$loginfo)
                {//记录不存在
                    $data = array(
                        'roomid' => $room_id,
                        'uid' => $userid,
                        'login_time' => time()
                    );
                    $db->insert('live_room_log')->cols($data)->query();
                }
                else
                {
                    $data = array(
                        'roomid' => $room_id,
                        'uid' => $userid,
                        'login_time' => time()
                    );
                    $db->update('live_room_log')->cols($data)->where('id='.$loginfo['id'])->query();
                }

                $_SESSION['in_time'] = time();

                if(!empty($info)){
                    if($info['num'] < $count){
                        //$db->insert('live_online')->cols(array('num'=>$count , 'atime'=>time() , 'adduser'=>$client_name ))->query();
                        $db->update('live_online')->cols(array('num'=>$count,'adduser'=>$info['adduser'].','.$client_name))->where('onlineid='.$info['onlineid'])->query();
                    }
                }else{
                    //$db->insert('live_online')->cols(array('num'=>$count , 'atime'=>time() , 'adduser'=>$client_name ))->query();
                    $db->insert('live_online')->cols(array('num'=>$count ,'today'=>$today,'roomid'=>$room_id,'h'=>$hour, 'atime'=>time() , 'adduser'=>$client_name ))->query();    
                }
                $onlinenum = $db->select('onlinenum')->from('live_liveroom')->where("roomid='".$room_id."'")->single();
                // echo 'kuangke--------------------------------------------';
                // echo($onlinenum);
                // echo 'kuangke--------------------------------------------';
                $onlinenum = intval($onlinenum) + 1;
                $db->update('live_liveroom')->cols(array('onlinenum' => $onlinenum))->where("roomid=$room_id")->query();
                // 给当前用户发送用户列表 
                $new_message['client_list'] = $clients_list;
                Gateway::sendToCurrentClient(json_encode($new_message));
				
				//记录当前连接时间
				$userid = intval($message_data['userid']);
				$userinfo = $db->select('userid')->from('live_userinfo_base')->where("userid='".$userid."'")->single();
				if($userinfo)
				{
					$_SESSION['userid'] = $userid;
					$data['room_id'] = $room_id;
					$data['userid'] = $userid;
                    $data['date'] = time();
                    unset($data['roomid']);
					$db->insert('live_user_inout_record')->cols($data)->query();
				}
                return;
                
                //客户端发言 message: {type:say, to_client_id:xx, content:xx}
            case 'say':
                // 非法请求
                if(!isset($_SESSION['room_id']))
                {
                    throw new \Exception("\$_SESSION['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
                }
                $room_id = intval($_SESSION['room_id']);
                $client_name = $_SESSION['client_name'];
                $content = filter_var($message_data['content'],FILTER_SANITIZE_MAGIC_QUOTES);
                $userid = intval($message_data['userid']);
				$chattime = $_SESSION['chattime'];
                $time = time();   //留言时间
                
                //判断此IP是否被禁言
				$db = Db::instance('db');
                $jinyan = $db->select('*')->from('live_jinyan')->where("uid='{$userid}'")->orderByASC(array('id'), false)->limit(1)->row();
				if((time() - $jinyan['date']) < 86400)
				{
					$new_message = array(
                        'type'=>'jinyan',
                        'from_client_id'=>$client_id, 
                        'from_client_name' =>'',
                        'to_client_id'=>$client_id,
                        'content'=>"delete",
                        'time'=>date('H:i')
                    );
                    Gateway::sendToClient($client_id, json_encode($new_message));
                    return;
				}

                //if($jinyan['date'])
                
                //聊天的间隔
                if($time - $chattime < 5){
                    $new_message = array(
                        'type'=>'refuse',
                        'from_client_id'=>$client_id, 
                        'from_client_name' =>'',
                        'to_client_id'=>$client_id,
                        'content'=>"delete",
                        'time'=>date('H:i')
                    );
                    Gateway::sendToClient($client_id, json_encode($new_message));
                    return ;
                }

                $db = Db::instance('db');
                
                $userid = $db->select('userid')->from('live_userinfo_base')->where("username='".$client_name."'")->single();
                
                $confval = $db->select('confval')->from('live_system_config')->where("confid=35")->single();
                
                $patterns = explode("\n", $confval);
                
                foreach ($patterns as $k => $v){
                    if (!empty($v)) { 
                        $content = str_replace($v, "*", $content);
                    }  
                }
               /* 
                $replaces = array_fill(0, count($patterns), '恭喜发财 ');
                $content = preg_replace($patterns, $replaces, $content);*/
				// foreach ($patterns as $k => $v){
                //     if (!empty($v)) { 
				// 		if(strpos($content, $v) !== false)
				// 		{
				// 			$content = '[玫瑰][玫瑰][玫瑰][玫瑰][玫瑰][玫瑰][玫瑰]';
				// 			break;
				// 		}
				// 	}  
                // }
				
                $sourceimg = filter_var($message_data['sourceimg'],FILTER_SANITIZE_MAGIC_QUOTES);   //上传 图片是否保存 
                $lastid = 0;
                $masterid = $room_id;   //原系统使用的是29 标记。 tank 后发系统使用的 房间的 id : $room_id
                $userid3 = intval($message_data['userid']);
                if(!$userid3){
                    $lastid = $db->insert('live_chatcontent')->cols(array('masterid'=>$masterid,'chatcontent'=>htmlspecialchars($content),'chatname'=>$client_name,'sourceimg'=>$sourceimg,'ctime'=>time(), 'ip' => $_SERVER['REMOTE_ADDR']))->query();
                }else{
                    $lastid = $db->insert('live_chatcontent')->cols(array('masterid'=>$masterid,'chatcontent'=>htmlspecialchars($content),'chatname'=>$client_name,'sourceimg'=>$sourceimg,'chatuserid'=>$userid3,'level'=>0,'ctime'=>time(), 'ip' => $_SERVER['REMOTE_ADDR'] ))->query();
                }
				
				 $_SESSION['chattime'] = time();
				 
                // 私聊
                if($message_data['to_client_id'] != 'all')
                {
                    $new_message = array(
                        'type'=>'say',
                        'from_client_id'=>$client_id, 
                        'from_client_name' =>$client_name,
                        'to_client_id'=>$message_data['to_client_id'],
                        'content'=>"<b>对你说: </b>".nl2br(htmlspecialchars($content)),
                        'time'=>date('H:i'),
                        'lastid'=>$lastid
                    );
                    Gateway::sendToClient($message_data['to_client_id'], json_encode($new_message));
                    $new_message['content'] = "<b>你对".htmlspecialchars($message_data['to_client_name'])."说: </b>".nl2br(htmlspecialchars($message_data['content']));
                    return Gateway::sendToCurrentClient(json_encode($new_message));
                }
                //判断是否被屏蔽
                $userid2 = intval($message_data['userid']);
                $pingbi = $db->select('*')->from('live_pingbi')->where("uid=$userid2")->limit(1)->row();
               
                if($pingbi)
                {
                    $new_message = array(
                        'type'=>'say',
                        'from_client_id'=>$client_id, 
                        'from_client_name' =>$client_name,
                        'to_client_id'=>'other',
                        'content'=> nl2br(htmlspecialchars($content)),
                        'sourceimg' =>$sourceimg,
                        'time'=>date('H:i'),
                        'lastid'=>$lastid
                    );
                    return Gateway::sendToCurrentClient(json_encode($new_message));
                }
                $new_message = array(
                    'type'=>'say', 
                    'from_client_id'=>$client_id,
                    'from_client_name' =>$client_name,
                    'to_client_id'=>'all',
                    'content'=>nl2br(htmlspecialchars($content)),
                    'sourceimg' =>$sourceimg,
                    'time'=>date('H:i'),
                    'lastid'=>$lastid
                );
                return Gateway::sendToGroup($room_id ,json_encode($new_message));
                
        }
   }
   
   /**
    * 当客户端断开连接时
    * @param integer $client_id 客户端id
    */
   public static function onClose($client_id)
   {
       // debug
       //echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";
       // 从房间的客户端列表中删除
       if(isset($_SESSION['room_id']))
       {
           $room_id = $_SESSION['room_id'];
           $new_message = array('type'=>'logout', 'from_client_id'=>$client_id, 'from_client_name'=>$_SESSION['client_name'], 'time'=>date('Y-m-d H:i:s'));
           
           Gateway::sendToGroup($room_id, json_encode($new_message));
		    //记录当前关闭连接时间
			$db = Db::instance('db');
			$userinfo = $db->select('userid')->from('live_userinfo_base')->where("userid='".$_SESSION['userid']."'")->single();
			if($userinfo)
			{
				
				$data['room_id'] = $_SESSION['room_id'];
				$data['userid'] = $_SESSION['userid'];
				$data['date'] = time();
				$data['type'] = 1;
                $db->insert('live_user_inout_record')->cols($data)->query();
                
                $data2 = array(
                    'roomid' => $room_id,
                    'in_time' => $_SESSION['in_time'],
                    'out_time' => time(),
                    'userid' => $data['userid'],
                    'username' => $_SESSION['client_name']
                );
                $db->insert('live_login_list_log')->cols($data2)->query();
            }
            
            $onlinenum = $db->select('onlinenum')->from('live_liveroom')->where("roomid='".$room_id."'")->single();
        //    echo '退出----kuangke--------------------------------------------';
        //      echo($onlinenum);
        //     echo '退出-----kuangke--------------------------------------------';
            //判断用户登录
            $userid = $_SESSION['userid'];
            $time = strtotime(date('Y-m-d'));
            $time_end = $time + 86400;
            $sql = "SELECT * FROM live_room_log WHERE uid={$userid} AND roomid={$room_id} AND login_time >= $time AND login_time < $time_end";
            $loginfo = $db->row($sql);
            if($loginfo)
            {//记录不存在
                $data = array(
                    'roomid' => $room_id,
                    'uid' => $userid,
                    'logout_time' => time()
                );
                $db->update('live_room_log')->cols($data)->where('id='.$loginfo['id'])->query();
            }

            $onlinenum = intval($onlinenum) - 1;
            $db->update('live_liveroom')->cols(array('onlinenum' => $onlinenum))->where("roomid=$room_id")->query();
        }
    }
}
