<?php


namespace choate\yii2\smscaptcha;

use choate\smses\Connection;
use yii\base\Action;
use Yii;
use yii\di\Instance;
use yii\web\BadRequestHttpException;

class CaptchaAction extends Action
{
    const STYLE_DIGIT = 'digit';

    const STYLE_ALPHA = 'alpha';

    /**
     * @var \choate\smses\Connection
     */
    public $smses = 'smses';

    /**
     * @var int how many times should the same CAPTCHA be displayed. Defaults to 3.
     * A value less than or equal to 0 means the test is unlimited (available since version 1.1.2).
     */
    public $testLimit = 3;

    /**
     * @var int the minimum length for randomly generated word. Defaults to 6.
     */
    public $minLength = 6;

    /**
     * @var int the maximum length for randomly generated word. Defaults to 7.
     */
    public $maxLength = 7;

    /**
     * @var string the fixed verification code. When this property is set,
     * [[getVerifyCode()]] will always return the value of this property.
     * This is mainly used in automated tests where we want to be able to reproduce
     * the same verification code each time we run the tests.
     * If not set, it means the verification code will be randomly generated.
     */
    public $fixedVerifyCode;

    /**
     * @var string the style for generated is string or a digit or alpha. Defaults string
     */
    public $style;

    /**
     * @var int
     */
    public $countdown = 60;

    /**
     * @var string
     */
    public $content = '短信验证码: {code}';

    /**
     * @var string the mobile for matched regular expression
     */
    public $pattern = '#^1\d{10}$#';

    /**
     * @var string
     */
    private $mobile;

    public function init()
    {
        $this->smses = Instance::ensure($this->smses, Connection::class);
    }


    /**
     * @param string $mobile
     * @return array
     * @throws BadRequestHttpException
     * @throws \choate\smses\Exception
     */
    public function run($mobile)
    {
        $this->setMobile($mobile);
        $session = Yii::$app->getSession();
        $session->open();
        $name = $this->getSessionKey().'countdown';
        $time = time();
        $countdown = $session[$name];
        if ($countdown < $time) {
            $code = $this->getVerifyCode($mobile, true);
            $content = strtr($this->content, ['{code}' => $code]);
            $this->smses->send($mobile, $content);
            $countdown = $this->countdown;
            $session[$name] = $time + $countdown;
        }

       return [
            'countdown' => $countdown,
        ];
    }


    /**
     * @param string $mobile
     * @param bool $regenerate
     * @return mixed|string
     * @throws BadRequestHttpException
     */
    public function getVerifyCode($mobile, $regenerate = false)
    {
        if ($this->fixedVerifyCode !== null) {
            return $this->fixedVerifyCode;
        }

        $this->setMobile($mobile);
        $session = Yii::$app->getSession();
        $session->open();
        $name = $this->getSessionKey();
        if ($session[$name] === null || $regenerate) {
            $session[$name] = $this->generateVerifyCode();
            $session[$name . 'count'] = 1;
        }

        return $session[$name];
    }

    /**
     * @param string $mobile
     * @param string $input
     * @param bool $caseSensitive
     * @return bool
     * @throws BadRequestHttpException
     */
    public function validate($mobile, $input, $caseSensitive)
    {
        $this->setMobile($mobile);
        $code = $this->getVerifyCode($mobile);
        $valid = $caseSensitive ? ($input === $code) : strcasecmp($input, $code) === 0;
        $session = Yii::$app->getSession();
        $session->open();
        $name = $this->getSessionKey() . 'count';
        $session[$name] = $session[$name] + 1;
        if ($valid || $session[$name] > $this->testLimit && $this->testLimit > 0) {
            $this->getVerifyCode($mobile, true);
        }

        return $valid;
    }

    /**
     * @return string
     */
    protected function generateVerifyCode()
    {
        switch ($this->style) {
            case self::STYLE_DIGIT:
                $code = $this->generateDigitCode();
                break;
            case self::STYLE_ALPHA:
                $code = $this->generateAlphaCode();
                break;
            default:
                $code = $this->generateStringCode();
        }

        return $code;
    }

    /**
     * Generates a number code.
     *
     * @return string the generated verification code
     */
    protected function generateDigitCode()
    {
        $letters = '24680';
        $vowels = '13579';
        return $this->generateAlgorithm($letters, $vowels);
    }

    /**
     * Generates a chat code.
     *
     * @return string the generated verification code
     */
    protected function generateAlphaCode()
    {
        $letters = 'bcdfghjklmnpqrstvwxyz';
        $vowels = 'aeiou';
        return $this->generateAlgorithm($letters, $vowels);
    }

    /**
     * Generates a string code.
     *
     * @return string the generated verification code
     */
    protected function generateStringCode()
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $vowels = '1234567890';
        return $this->generateAlgorithm($letters, $vowels);
    }

    /**
     * Generates a algorithm.
     *
     * @param string $letters
     * @param string $vowels
     *
     * @return string the generated verification code
     */
    protected function generateAlgorithm($letters, $vowels)
    {
        $vowelsLength = strlen($vowels) - 1;
        $lettersLength = strlen($letters) - 1;
        if ($this->minLength < 3) {
            $this->minLength = 3;
        }
        if ($this->maxLength > 20) {
            $this->maxLength = 20;
        }
        if ($this->minLength > $this->maxLength) {
            $this->maxLength = $this->minLength;
        }
        $length = mt_rand($this->minLength, $this->maxLength);
        $code = '';
        for ($i = 0; $i < $length; ++$i) {
            if ($i % 2 && mt_rand(0, 10) > 2 || !($i % 2) && mt_rand(0, 10) > 9) {
                $code .= $vowels[mt_rand(0, $vowelsLength)];
            } else {
                $code .= $letters[mt_rand(0, $lettersLength)];
            }
        }
        return $code;
    }

    protected function getSessionKey()
    {
        return '__captcha/' . $this->getUniqueId() . '/' . $this->getMobile();
    }

    /**
     * @param string $mobile
     * @throws BadRequestHttpException
     */
    protected function setMobile($mobile)
    {
        if (!preg_match($this->pattern, $mobile)) {
            throw new BadRequestHttpException(Yii::t('yii', 'Invalid data received for parameter "{param}".', ['param' => $mobile]));
        }
        $this->mobile = $mobile;
    }

    /**
     * @return string
     */
    protected function getMobile()
    {
        return $this->mobile;
    }
}