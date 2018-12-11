<?php

namespace choate\yii2\smscaptcha;

interface SenderInterface
{
    public function send($mobile, $content);
}