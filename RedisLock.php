<?php

class RedisLock
{
    /**
     * @var Redis
     */
    protected $redis;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var int
     */
    protected $expire;

    /**
     * @var string
     */
    protected $owner;

    /**
     * @var int
     */
    protected $retry;

    /**
     * @var int
     */
    protected $sleep;


    public function __construct(Redis $redis, string $name = '', int $expire = 5, string $owner = '', int $retry = 5, int $sleep = 500000)
    {
        $this->redis = $redis;
        $this->name = $name;
        $this->expire = $expire > 0 ? $expire : 5;
        $this->owner = $owner ?: $this->setOwner();
        $this->retry = $retry;
        $this->sleep = $sleep;
    }

    protected function setOwner($length = 16): string
    {
        $string = '';

        while (($len = strlen($string)) < $length) {
            $size = $length - $len;

            $bytes = random_bytes($size);

            $string .= substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $size);
        }

        return $string;
    }

    public function lock(): int
    {
        $result = 0;

        while ($this->retry-- > 0) {
            $result = $this->redis->set($this->name, $this->owner, ['nx', 'ex' => $this->expire]);

            if ($result) {
                break;
            }

            usleep($this->sleep);
        }

        return $result;
    }

    public function release(): int
    {
        $lua = <<<LUA
if redis.call('get', KEYS[1]) == ARGV[1]
    then
        return redis.call('del', KEYS[1])
    else
        return 0
end
LUA;

        return $this->redis->eval($lua, [$this->name, $this->owner], 1);
    }
}

////////////////////////////////

$redis = new Redis();
$redis->connect('192.168.0.14', 6379);

$lock = new RedisLock($redis, 'foo');

if ($lock->lock()) {
    var_dump('执行业务');
    sleep(5);
    var_dump('完成业务');

    $res = $lock->release();
    var_dump("解锁成功: $res");
    return;
} else {
    var_dump('获取锁失败');
}

