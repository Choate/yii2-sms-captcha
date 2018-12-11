<?php
/**
 * Created by PhpStorm.
 * User: Choate
 * Date: 2018/11/29
 * Time: 10:14
 */

namespace choate\yii2\smscaptcha;


use Yii;
use yii\base\InvalidConfigException;
use yii\validators\Validator;
use yii\web\BadRequestHttpException;

class CaptchaValidator extends Validator
{
    /**
     * @var bool whether to skip this validator if the input is empty.
     */
    public $skipOnEmpty = false;
    /**
     * @var bool whether the comparison is case sensitive. Defaults to false.
     */
    public $caseSensitive = false;
    /**
     * @var string the route of the controller action that renders the CAPTCHA image.
     */
    public $captchaAction = 'site/captcha';

    public $mobile;

    public $targetAttribute;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        if (empty($this->mobile) && empty($this->targetAttribute)) {
            throw new InvalidConfigException('Either "mobile" or "targetAttribute" must be set.');
        }
        if ($this->message === null) {
            $this->message = Yii::t('yii', 'The verification code is incorrect.');
        }
    }

    /**
     * @param mixed $value
     * @return array|null
     * @throws InvalidConfigException
     */
    protected function validateValue($value)
    {
        $captcha = $this->createCaptchaAction();
        try {
            $valid = !is_array($value) && $captcha->validate($this->mobile, $value, $this->caseSensitive);
        } catch (BadRequestHttpException $e) {
            $valid = false;
        }

        return $valid ? null : [$this->message, []];
    }

    /**
     * @return null|CaptchaAction
     * @throws InvalidConfigException
     */
    public function createCaptchaAction()
    {
        $ca = Yii::$app->createController($this->captchaAction);
        if ($ca !== false) {
            /* @var $controller \yii\base\Controller */
            list($controller, $actionID) = $ca;
            $action = $controller->createAction($actionID);
            if ($action !== null) {
                return $action;
            }
        }
        throw new InvalidConfigException('Invalid CAPTCHA action ID: ' . $this->captchaAction);
    }

    /**
     * @inheritdoc
     */
    public function validateAttribute($model, $attribute)
    {
        if ($this->mobile === null) {
            $this->mobile = $model->{$this->targetAttribute};
        }

        parent::validateAttribute($model, $attribute);
    }
}