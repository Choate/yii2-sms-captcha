<?php

namespace choate\yii2\smscaptcha;


use yii\base\Component;
use yii\caching\Cache;
use yii\helpers\Json;

class Storage extends Component implements \JsonSerializable
{
    private $cache;

    private $name;

    private $code;

    private $countdown = 0;

    private $count = 1;

    public function __construct(Cache $cache, array $config = [])
    {
        $this->cache = $cache;
        parent::__construct($config);
    }

    public function jsonSerialize()
    {
        return ['name' => $this->getName(), 'code' => $this->getCode(), 'countdown' => $this->getCountdown(), 'count' => $this->getCount()];
    }

    /**
     * @param Cache $cache
     * @param $name
     * @return Storage
     */
    public static function load(Cache $cache, $name)
    {
        $value = $cache->get($name);
        $config = ['name' => $name];
        if ($value) {
            $config = Json::decode($value);
        }

        return new self($cache, $config);
    }

    public function save($expired)
    {
        $cache = $this->cache;
        $value = Json::encode($this);
        $cache->set($this->name, $value, null);
    }

    public function destruct()
    {
        $cache = $this->cache;
        $cache->delete($this->name);
    }


    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return mixed
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @param mixed $code
     */
    public function setCode($code)
    {
        $this->code = $code;
    }

    /**
     * @return mixed
     */
    public function getCountdown()
    {
        return $this->countdown;
    }

    /**
     * @param mixed $expired
     */
    public function setCountdown($countdown)
    {
        $this->countdown = $countdown;
    }

    /**
     * @return mixed
     */
    public function getCount()
    {
        return $this->count;
    }

    /**
     * @param mixed $count
     */
    public function setCount($count)
    {
        $this->count = $count;
    }

    /**
     * @param int $count
     */
    public function increaseCount($count = 1)
    {
        $this->count += $count;
    }
}