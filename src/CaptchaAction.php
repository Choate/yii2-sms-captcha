<?php


namespace choate\yii2\smscaptcha;

use yii\base\Action;
use Yii;
use yii\base\Model;
use yii\caching\Cache;
use yii\di\Instance;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class CaptchaAction extends Action
{
    const STYLE_DIGIT = 'digit';

    const STYLE_ALPHA = 'alpha';

    /**
     * @var SenderInterface
     */
    public $sender = 'sender';

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
     * @var Cache
     */
    public $cache = 'cache';

    /**
     * @var int 过期时间
     */
    public $expired = 1800;

    /**
     * @var 过滤器
     */
    public $filter;

    /**
     * @var string
     */
    public $mobile;

    /**
     * @var Storage
     */
    protected $storage;

    public function init()
    {
        $this->sender = Instance::ensure($this->sender, SenderInterface::class);
        $this->cache = Instance::ensure($this->cache, Cache::class);
    }


    /**
     * @param string $mobile
     * @return array
     * @throws BadRequestHttpException
     * @throws \choate\smses\Exception
     */
    public function run()
    {
        $request = Yii::$app->getRequest();
        $response = Yii::$app->getResponse();
        $response->format = Response::FORMAT_JSON;

        if (($result = $this->filter()) !== true) {
            return $result;
        }

        $mobile = $this->mobile ?: $request->post('mobile');
        $this->setMobile($mobile);
        $storage = $this->getStorage();
        $time = time();
        $isSend = false;
        $countdown = $storage->getCountdown() ?: $time;
        if ($countdown <= $time) {
            $code = $this->getVerifyCode($mobile, true);
            $content = strtr($this->content, ['{code}' => $code]);
            $this->sender->send($mobile, $content);
            $countdown = $time + $this->countdown;
            $storage->setCountdown($countdown);
            $storage->save(null);
            $isSend = true;
        }

        return [
            'is_send' => $isSend,
            'countdown' => $countdown - $time,
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
        $storage = $this->getStorage();
        if ($regenerate) {
            $storage->setCode($this->generateVerifyCode());
            $storage->setCount(1);
            $storage->save($this->expired);
        }

        return $storage->getCode();
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
        $storage = $this->getStorage();
        $storage->increaseCount(1);
        $code = $this->getVerifyCode($mobile);
        $valid = $caseSensitive ? ($input === $code) : strcasecmp($input, $code) === 0;
        if ($valid || $storage->getCount() > $this->testLimit && $this->testLimit > 0) {
            $storage->destruct();
        } else {
            $storage->save(null);
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
            throw new BadRequestHttpException(Yii::t('yii', 'Invalid data received for parameter "{param}".', ['param' => 'mobile']));
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

    /**
     * @return Storage
     */
    protected function getStorage()
    {
        if (empty($this->storage)) {
            $this->storage = Storage::load($this->cache, $this->getSessionKey());
        }

        return $this->storage;
    }

    protected function filter()
    {
        $response = Yii::$app->getResponse();
        $filter = $this->filter;
        $valid = true;
        if ($filter instanceof Model) {
            $filter->load($_POST, '');
            $valid = $filter->validate();
            if (!$valid) {
                $result = [];
                foreach ($filter->getFirstErrors() as $name => $message) {
                    $result[] = [
                        'field' => $name,
                        'message' => $message,
                    ];
                }
            }
        } elseif ($filter instanceof \Closure) {
            $result = call_user_func($this->filter);
            $valid = $result === true;
        }

        if ($valid) {
            return true;
        } else {
            $response->setStatusCode(422, 'Data Validation Failed.');

            return $result;
        }
    }
}