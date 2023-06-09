<?php

namespace App\Services\Umeng;


use App\Exceptions\ApiExceptions;
use Illuminate\Support\Facades\Log;
use Laravel\Lumen\Application;

/**
 * 类名：UmengVerifyService
 * 功能：友盟一键登录
 * https://developer.umeng.com/docs/143070/detail/145892
 */
class UmengVerifyService {

    /**
     * 阿里云市场购买应用的appKey
     * @var Application|mixed|string
     */
    protected $appKey = '';

    /**
     * 阿里云市场购买应用的appSecret
     * @var Application|mixed|string
     */
    protected $appSecret = '';

    /**
     * 友盟的appkey
     * @var Application|mixed
     */
    protected $um_appkey;


    protected $host = 'https://verify5.market.alicloudapi.com';

    protected $path = '/api/v1/mobile/info';

    protected $method = 'POST';

    protected $accept = 'application/json';

    protected $content_type = 'application/json; charset=UTF-8';

    protected $log;

    public function __construct($platform = ANDROID)
    {

        if($platform == ANDROID){
            $this->um_appkey = config('umeng.app_key_an');
        }else{
            $this->um_appkey = config('umeng.app_key_ios');
        }

        $this->appKey = config('umeng.ali_key');
        $this->appSecret = config('umeng.ali_secret');
        $this->log = Log::channel('umeng_login');
    }

    public function info($token)
    {
        $post_data['token'] = $token;
        //um_appkey为用户在友盟注册的应用分配的appKey
        $curl_url = $this->host . $this->path . "?appkey=" . $this->um_appkey;
        $header = $this->headers();
        $headerArray = $this->headerArray($header);
        return $this->curl($curl_url, $headerArray, $post_data);
    }

    protected function headers()
    {
        $header["Accept"] = $this->accept;
        $header["Content-Type"] = $this->content_type;
        $header["X-Ca-Version"] = "1";
        $header["X-Ca-Signature-Headers"] = "X-Ca-Key,X-Ca-Nonce,X-Ca-Stage,X-Ca-Timestamp,X-Ca-Version";
        $header["X-Ca-Stage"] = "RELEASE";
        //请求的阿里云AppKey
        $header["X-Ca-Key"] = $this->appKey;
        $header["X-Ca-Timestamp"] = strval(time() * 1000);
        mt_srand((double)microtime() * 10000);
        $uuid = strtoupper(md5(uniqid(rand(), true)));
        $header["X-Ca-Nonce"] = strval($uuid);
        //Headers
        $headers = "X-Ca-Key:" . $header["X-Ca-Key"] . PHP_EOL;
        $headers .= "X-Ca-Nonce:" . $header["X-Ca-Nonce"] . PHP_EOL;
        $headers .= "X-Ca-Stage:" . $header["X-Ca-Stage"] . PHP_EOL;
        $headers .= "X-Ca-Timestamp:" . $header["X-Ca-Timestamp"] . PHP_EOL;
        $headers .= "X-Ca-Version:" . $header["X-Ca-Version"] . PHP_EOL;
        //um_appkey为用户在友盟注册的应用分配的appKey,token和phoneNumber是app传过来的值
        $url = $this->path . "?appkey=" . $this->um_appkey;
        //sign
        $str_sign = $this->method . PHP_EOL;
        $str_sign .= $this->accept . PHP_EOL;
        $str_sign .= PHP_EOL;
        $str_sign .= $this->content_type . PHP_EOL;
        $str_sign .= PHP_EOL;
        $str_sign .= $headers;
        $str_sign .= $url;
        $sign = base64_encode(hash_hmac('sha256', $str_sign, $this->appSecret, true)); //secret为APP的密钥
        $header['X-Ca-Signature'] = $sign;
        return $header;
    }

    protected function headerArray($header)
    {
        $headerArray = [];
        foreach ($header as $k => $v) {
            array_push($headerArray, $k . ":" . $v);
        }
        return $headerArray;
    }

    protected function curl($curl_url, $headerArray, $post_data)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->method);
        curl_setopt($curl, CURLOPT_URL, $curl_url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headerArray);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($post_data));
        $result = curl_exec($curl);
        curl_close($curl);
        return json_decode($result, true);
    }

    /**
     * 一键登录电话号码
     * @link https://developer.umeng.com/docs/143070/detail/144783
     * @param $token
     * @param $verifyId
     * @return mixed
     * @throws ApiExceptions
     */
    public function getMobile($token,$verifyId)
    {

        try {
            $response = $this->info($token);

            $this->log->info(json_encode($response));

            if(!$response['success'] || empty($response['data']['mobile'])){
                throw new ApiExceptions($response['message'] ?? '失败');
            }
            return $response['data']['mobile'];

        }catch (\Exception $e){
            throw new ApiExceptions($e->getMessage());
        }

    }
}
