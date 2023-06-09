<?php

namespace App\Services\SensitiveWord;

/**
 * 敏感词
 * Class SensitiveWordNodeService
 * @package App\Services\SensitiveWord
 */
class SensitiveWordNodeService {

    public $isEndOfWord;
    public $children;

    public function __construct()
    {
        $this->isEndOfWord = false;
        $this->children = [];
    }

}
