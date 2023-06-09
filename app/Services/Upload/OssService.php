<?php

namespace App\Services\Upload;

use Illuminate\Support\Facades\Log;
use OSS\Core\OssException;
use OSS\OssClient;

/**
 * OSS上传服务
 * Class OssService
 * @package App\Services\Upload
 */
class OssService
{
    private $accessKeyId;
    private $accessKeySecret;
    private $endpoint;
    private $bucket;
    private $log;

    //文件用途类型
    const SOURCE_TYPE_DEFAULT    = 0;
    const SOURCE_TYPE_AVATAR     = 1;
    const SOURCE_TYPE_FEEDBACK   = 2;
    const  SOURCE_TYPE_MAP= [
        self::SOURCE_TYPE_DEFAULT    => 'default',  //默认
        self::SOURCE_TYPE_AVATAR     => 'avatar',   //头像
        self::SOURCE_TYPE_FEEDBACK   => 'feedback', //用户反馈
    ];

    public function __construct()
    {
        $this->accessKeyId     = config('filesystems.disks.oss.access_id');
        $this->accessKeySecret = config('filesystems.disks.oss.access_key');
        $this->endpoint        = config('filesystems.disks.oss.endpoint');
        $this->bucket          = config('filesystems.disks.oss.bucket');
        $this->log             = Log::channel('oss_upload');
    }

    /**
     * 简单上传
     * @param $name
     * @param $file
     * @return null|mixed
     */
    public function uploadFile($name,$file){
        try{
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
            return $ossClient->uploadFile($this->bucket, $name, $file);
        } catch(OssException $e) {
            $this->log->info(json_encode("ERR:".$e->getMessage()));
            return null;
        }
    }

}
