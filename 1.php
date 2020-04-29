<?php
require 'path_to_sdk/autoload.php';
use Qiniu\Auth;

class qinu_class{


    public $accessKey="0YsjC666EnIrDKZfN9Widd78J19SmnQ0FPFUPAs8";
    public $secretKey="Mr2_vTs_jOwhSojJQiQbqZhc6fdloLVjRPJQtEqt";

    public function aa(){

        $bucket="dsdsd";
//        $policy = array(
//            'returnUrl' => 'http://127.0.0.1/demo/simpleuploader/fileinfo.php',
//            'returnBody' => '{"fname": $(fname)}',
//        );
        $auth = new Auth($this->accessKey, $this->secretKey);
        $upToken = $auth->uploadToken($bucket);

        return $upToken;

    }


}


       $qi = new qinu_class();

       $ds = $qi->aa();

       echo $ds;