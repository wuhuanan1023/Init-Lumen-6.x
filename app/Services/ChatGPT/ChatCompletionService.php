<?php

namespace App\Services\ChatGPT;

use App\Code\RedisCode;
use App\Models\Chat\ChatHistory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Class ChatCompletionService
 * @package App\Services\ChatGPT
 */
class ChatCompletionService {

    private $api_url = 'https://api.openai.com/v1/chat/completions';
    private $api_key = '';
    private $streamHandler;
    private $dfa = null;
    private $check_sensitive = false;
    private $log;

    public function __construct() {
        $this->api_key = config('openai.api_key');
        $this->log = Log::channel('chat_completion');
    }

    public function set_dfa($dfa){
        $this->dfa = $dfa;
        //关闭答案敏感词
//        if(!empty($this->dfa) && $this->dfa->is_available()){
//            $this->check_sensitive = true;
//        }
    }

    public function send($question,$chat_id,$user_id = 0){

        //上下文联系
        $cache_key  = RedisCode::userChatHistory($chat_id);
        $cache_data = Redis::get($cache_key);
        $messages = $cache_data ? json_decode($cache_data,true) : [];
        //@todo 上下文长度限制
        array_push($messages,[
            'role' => ChatHistory::ROLE_MAP[ChatHistory::ROLE_USER],
            'content' => $question,
        ]);

        $this->streamHandler = new StreamService($question,$chat_id,$user_id);
        if($this->check_sensitive){
            $this->streamHandler->set_dfa($this->dfa);
        }

        if(empty($this->api_key)){
            $this->streamHandler->end('OpenAI 的 api key 还没填');
            return;
        }

        // 开启检测且提问包含敏感词
        if($this->dfa->containsSensitiveWords($question)){
            $this->streamHandler->end('您的问题不合适，AI暂时无法回答');
            return;
        }

        $json = json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'temperature' => 0.6,
            'stream' => true,
//            'max_tokens' => 1000
        ]);

        $headers = array(
            "Content-Type: application/json",
            "Authorization: Bearer ".$this->api_key,
        );

        $this->openai($json, $headers);

    }

    private function openai($json, $headers){
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, [$this->streamHandler, 'callback']);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->log->info(curl_error($ch));
        }

        curl_close($ch);
    }

}
