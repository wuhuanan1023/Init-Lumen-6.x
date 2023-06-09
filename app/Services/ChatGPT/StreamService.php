<?php

namespace App\Services\ChatGPT;

use App\Code\RedisCode;
use App\Models\Chat\ChatHistory;
use Illuminate\Support\Facades\Redis;

/**
 * Class StreamService
 * @package App\Services\ChatGPT
 */
class StreamService {

    private $data_buffer;//缓存，有可能一条data被切分成两部分了，无法解析json，所以需要把上一半缓存起来
    private $counter;//数据接收计数器
    private $qmd5;//问题md5
    private $chars;//字符数组，开启敏感词检测时用于缓存待检测字符
    private $punctuation;//停顿符号
    private $dfa = null;
    private $check_sensitive = false;
    private $complete_content;
    private $user_id;
    private $chat_id;
    private $question;

    public function __construct($question,$chat_id,$user_id = 0) {
        $this->buffer = '';
        $this->counter = 0;
        $this->qmd5 = md5($question .''.time()) ?? time();
        $this->chars = [];
        $this->lines = [];
        $this->punctuation = ['，', '。', '；', '？', '！', '……'];
        $this->complete_content = '';
        $this->user_id = $user_id;
        $this->chat_id = $chat_id;
        $this->question = $question;

    }

    public function set_dfa(&$dfa){
        $this->dfa = $dfa;
        if(!empty($this->dfa) && $this->dfa->is_available()){
            $this->check_sensitive = true;
        }
    }

    public function callback($ch, $data) {
        $this->counter += 1;

        $result = json_decode($data, true);
        if(is_array($result)){
            $this->end('openai 请求错误：'.json_encode($result));
            return strlen($data);
        }

        /*
            此处步骤仅针对 openai 接口而言
            每次触发回调函数时，里边会有多条data数据，需要分割
            如某次收到 $data 如下所示：
            data: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{"role":"assistant"},"index":0,"finish_reason":null}]}\n\ndata: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{"content":"以下"},"index":0,"finish_reason":null}]}\n\ndata: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{"content":"是"},"index":0,"finish_reason":null}]}\n\ndata: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{"content":"使用"},"index":0,"finish_reason":null}]}

            最后两条一般是这样的：
            data: {"id":"chatcmpl-6wimHHBt4hKFHEpFnNT2ryUeuRRJC","object":"chat.completion.chunk","created":1679453169,"model":"gpt-3.5-turbo-0301","choices":[{"delta":{},"index":0,"finish_reason":"stop"}]}\n\ndata: [DONE]

            根据以上 openai 的数据格式，分割步骤如下：
        */

        // 0、把上次缓冲区内数据拼接上本次的data
        $buffer = $this->data_buffer.$data;

        //拼接完之后，要把缓冲字符串清空
        $this->data_buffer = '';

        // 1、把所有的 'data: {' 替换为 '{' ，'data: [' 换成 '['
        $buffer = str_replace('data: {', '{', $buffer);
        $buffer = str_replace('data: [', '[', $buffer);

        // 2、把所有的 '}\n\n{' 替换维 '}[br]{' ， '}\n\n[' 替换为 '}[br]['
        $buffer = str_replace('}'.PHP_EOL.PHP_EOL.'{', '}[br]{', $buffer);
        $buffer = str_replace('}'.PHP_EOL.PHP_EOL.'[', '}[br][', $buffer);

        // 3、用 '[br]' 分割成多行数组
        $lines = explode('[br]', $buffer);

        // 4、循环处理每一行，对于最后一行需要判断是否是完整的json
        $line_c = count($lines);
        foreach($lines as $li=>$line){
            if(trim($line) == '[DONE]'){
                //数据传输结束
                $this->data_buffer = '';
                $this->counter = 0;
                $this->sensitive_check();
                $this->end();
                break;
            }
            $line_data = json_decode(trim($line), true);
            if( !is_array($line_data) || !isset($line_data['choices']) || !isset($line_data['choices'][0]) ){
                if($li == ($line_c - 1)){
                    //如果是最后一行
                    $this->data_buffer = $line;
                    break;
                }
                continue;
            }

            if( isset($line_data['choices'][0]['delta']) && isset($line_data['choices'][0]['delta']['content']) ){
                $this->sensitive_check($line_data['choices'][0]['delta']['content']);
            }
        }
        return strlen($data);
    }

    private function sensitive_check($content = null){
        // 如果不检测敏感词，则直接返回给前端
        if(!$this->check_sensitive){
            $this->write($content);
            return;
        }
        //每个 content 都检测是否包含换行或者停顿符号，如有，则成为一个新行
        if(!$this->has_pause($content)){
            $this->chars[] = $content;
            return;
        }
        $this->chars[] = $content;
        $content = implode('', $this->chars);
        if($this->dfa->containsSensitiveWords($content)){
            $content = $this->dfa->replaceWords($content);
            $this->write($content);
        }else{
            foreach($this->chars as $char){
                $this->write($char);
            }
        }
        $this->chars = [];
    }

    private function has_pause($content){
        if($content == null){
            return true;
        }
        $has_p = false;
        if(is_numeric(strripos(json_encode($content), '\n'))){
            $has_p = true;
        }else{
            foreach($this->punctuation as $p){
                if( is_numeric(strripos($content, $p)) ){
                    $has_p = true;
                    break;
                }
            }
        }
        return $has_p;
    }

    private function write($content = null, $flush=true){
        if($content != null){
            echo 'data: '.json_encode(['time'=>time(), 'code' => 1 ,'chat_id'=>$this->chat_id,'content'=>$content], JSON_UNESCAPED_UNICODE).PHP_EOL.PHP_EOL;
            $this->complete_content .= $content;
        }

        if($flush){
            flush();
        }
    }

    public function end($content = null){


        if(!empty($content)){
            $this->write($content, false);
        }
        echo 'retry: 86400000'.PHP_EOL;
        echo 'event: close'.PHP_EOL;
        echo 'data: Connection closed'.PHP_EOL.PHP_EOL;
        flush();

        //缓存聊天内容
        $cache_key = RedisCode::userChatHistory($this->chat_id);
        $cache_data = Redis::get($cache_key);
        $messages = $cache_data ? json_decode($cache_data,true) : [];
        array_push($messages,[
            'role' => ChatHistory::ROLE_MAP[ChatHistory::ROLE_USER],
            'content' => $this->question,
        ]);
        array_push($messages,[
            'role' => ChatHistory::ROLE_MAP[ChatHistory::ROLE_ASSISTANT],
            'content' => $this->complete_content,
        ]);
        Redis::set($cache_key,json_encode($messages));

        //存储聊天回答
        ChatHistory::query()->create([
            'user_id' => $this->user_id,
            'chat_id' => $this->chat_id,
            'role' => ChatHistory::ROLE_ASSISTANT,
            'content' => $this->complete_content,
            'created_ts' => time(),
        ]);

    }


}
