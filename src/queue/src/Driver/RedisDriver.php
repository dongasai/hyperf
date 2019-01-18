<?php
declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://hyperf.org
 * @document https://wiki.hyperf.org
 * @contact  group@hyperf.org
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Queue\Driver;

use Hyperf\Queue\JobInterface;
use Hyperf\Queue\Message;
use Hyperf\Queue\MessageInterface;
use Psr\Container\ContainerInterface;

class RedisDriver extends Driver
{
    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * @var string
     */
    protected $channel;

    /**
     * Key for waiting message.
     * @var string
     */
    protected $waiting;

    /**
     * Key for reserved message.
     * @var string
     */
    protected $reserved;

    /**
     * Key for delayed message.
     * @var string
     */
    protected $delayed;

    /**
     * Key for failed message.
     * @var string
     */
    protected $failed;

    /**
     * @var int
     */
    protected $timeout;

    /**
     * @var int
     */
    protected $retrySeconds;

    public function __construct(ContainerInterface $container, $config)
    {
        parent::__construct($container, $config);
        $this->redis = $container->get(\Redis::class);
        $this->channel = $config['channel'] ?? 'queue';
        $this->timeout = $config['timeout'] ?? 5;
        $this->retrySeconds = $config['retry_seconds'] ?? 10;

        $this->waiting = "{$this->channel}:waiting";
        $this->reserved = "{$this->channel}:reserved";
        $this->delayed = "{$this->channel}:delayed";
        $this->failed = "{$this->channel}:failed";
    }

    public function push(JobInterface $job): bool
    {
        $message = new Message($job);
        $data = $this->packer->pack($message);
        return (bool)$this->redis->lPush($this->waiting, $data);
    }

    public function delay(JobInterface $job, int $delay = 0): bool
    {
        if ($delay === 0) {
            return $this->push($job);
        }

        $message = new Message($job);
        $data = $this->packer->pack($message);
        return $this->redis->zAdd($this->delayed, time() + $delay, $data) > 0;
    }

    public function pop(int $timeout = 0): array
    {
        $this->move($this->delayed);
        $this->move($this->reserved);

        $res = $this->redis->brPop($this->waiting, $timeout);
        if (!isset($res[1])) {
            return [false, null];
        }

        $data = $res[1];
        $message = $this->packer->unpack($data);
        if (!$message) {
            return [false, null];
        }

        $this->redis->zadd($this->reserved, time() + 10, $data);

        return [$data, $message];
    }

    public function ack($data): bool
    {
        return $this->remove($data);
    }

    public function fail($data): bool
    {
        if ($this->remove($data)) {
            return (bool)$this->redis->lPush($this->failed, $data);
        }
    }

    protected function retry(MessageInterface $message)
    {
        $data = $this->packer->pack($message);
        $this->redis->zAdd($this->delayed, time() + $this->retrySeconds, $data);
    }

    /**
     * Remove data from reserved queue.
     * @return bool
     */
    protected function remove($data): bool
    {
        return $this->redis->zrem($this->reserved, $data) > 0;
    }

    /**
     * Move message to the waiting queue.
     * @param string $from
     */
    protected function move($from)
    {
        $now = time();
        if ($expired = $this->redis->zrevrangebyscore($from, (string)$now, '-inf')) {
            foreach ($expired as $job) {
                if ($this->redis->zRem($from, $job) > 0) {
                    $this->redis->lPush($this->waiting, $job);
                }
            }
        }
    }
}
