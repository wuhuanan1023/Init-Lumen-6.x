<?php

namespace App\Services\Sms;

use App\Exceptions\ApiExceptions;
use App\Models\Sms\SmsSendLog;

/**
 * 类名：SmsWlwxService
 * 功能：未来无线接口请求类
 * https://www.wlwx.com/client#/application/sms
 * 客户账号：560030
 * 客户密码：9SLXW9YA7R
 * 发送速率：100条/秒
 * 字数：70字（含签名）
 * 长短信：67字（含签名）
 * 对接URL：https://smsapp.wlwx.com
 * 端口：443
 * 接入号：106913163700741
 */
class SmsWlwxService {

    /**
     * 开发者平台分配的cust_code
     * @var
     */
    private $cust_code;

    /**
     * 开发者平台分配的cust_pass
     * @var
     */
    private $cust_pass;


    public function __construct()
    {
        $this->cust_code = config('sms.wlwx.cust_code');
        $this->cust_pass = config('sms.wlwx.cust_pass');
    }

    /**
     * 发送短信验证码
     * @param $mobile
     * @param $content
     * @param $scene
     * @return bool
     * @throws ApiExceptions
     */
    public function sendSms($mobile,$content, $scene)
    {

        try {
            $postDataArr = [
                'cust_code' => $this->cust_code,
                'destMobiles' => $mobile,
                'content' => $content,
                'sign' => md5($content.$this->cust_pass)
            ];
            $postDataString = json_encode($postDataArr);
            $re = $this->httpPost('https://smsapp.wlwx.com/sendsms',$postDataString);
            $result = json_decode($re,true);
            if($result['status'] == 'success'){

                //短信日志
                SmsSendLog::query()->create([
                    'mobile' => $mobile,
                    'scene'  => $scene,
                    'content' => $content,
                    'send_ts' => time(),
                    'send_channel' => '未来无线',
                    'send_result' => $re,
                ]);

                return true;
            }else{
                throw new ApiExceptions($result['respMsg']);
            }
        }catch (\Exception $e){
            throw new ApiExceptions($e->getMessage());
        }

    }

    /**
     * @param $url
     * @param $data_string
     * @return bool|string
     */
    private function httpPost($url, $data_string) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'X-AjaxPro-Method:ShowList',
                'Content-Type: application/json; charset=utf-8',
                'Content-Length: ' . strlen($data_string))
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }


}
