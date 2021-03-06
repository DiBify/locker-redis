<?php
/**
 * Created for locker-redis
 * Date: 31.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace DiBify\Locker\Redis;


use DiBify\DiBify\Exceptions\InvalidArgumentException;
use DiBify\DiBify\Locker\LockerInterface;
use DiBify\DiBify\Locker\WaitForLockTrait;
use DiBify\DiBify\Model\Reference;
use DiBify\DiBify\Model\ModelInterface;
use Redis;

class Locker implements LockerInterface
{

    use WaitForLockTrait;

    private Redis $redis;

    private string $keyPrefix;

    private int $defaultTimeout;

    private int $maxTimeout;

    public function __construct(Redis $redis, string $keyPrefix = 'Locker:', int $defaultTimeout = 5, int $maxTimeout = 60)
    {
        $this->redis = $redis;
        $this->keyPrefix = $keyPrefix;
        $this->defaultTimeout = $defaultTimeout;
        $this->maxTimeout = $maxTimeout;
    }

    /**
     * @inheritDoc
     * @throws InvalidArgumentException
     */
    public function lock(ModelInterface $model, ModelInterface $locker, int $timeout = null): bool
    {
        $this->guardTimeout($timeout);

        $modelKey = $this->getKeyPrefix() . $this->getModelIdentity($model);
        $modelLocker = $this->getModelIdentity($locker);

        if ($this->getRedis()->setnx($modelKey, $modelLocker)) {
            $this->getRedis()->expire($modelKey, $timeout ?? $this->getDefaultTimeout());
            return true;
        }

        $current = $this->getRedis()->get($modelKey);
        if ($current === $modelLocker) {
            $this->getRedis()->expire($modelKey, $timeout ?? $this->getDefaultTimeout());
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function unlock(ModelInterface $model, ModelInterface $locker): bool
    {
        $modelKey = $this->getKeyPrefix() . $this->getModelIdentity($model);
        $modelLocker = $this->getModelIdentity($locker);

        $currentLocker = $this->getRedis()->get($modelKey);

        if ($currentLocker === false) {
            return true;
        }

        if ($currentLocker === $modelLocker) {
            $this->getRedis()->del($modelKey);
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     * @throws InvalidArgumentException
     */
    public function passLock(ModelInterface $model, ModelInterface $currentLocker, ModelInterface $nextLocker, int $timeout = null): bool
    {
        $this->guardTimeout($timeout);

        $modelKey = $this->getKeyPrefix() . $this->getModelIdentity($model);
        $modelCurrentLocker = $this->getModelIdentity($currentLocker);
        $modelNextLocker = $this->getModelIdentity($nextLocker);

        $modelLocker = $this->getRedis()->get($modelKey);

        if ($modelLocker === false) {
            return $this->lock($model, $nextLocker, $timeout);
        }

        if ($modelLocker === $modelCurrentLocker) {
            $this->getRedis()->set($modelKey, $modelNextLocker, $timeout);
            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function isLockedFor(ModelInterface $model, ModelInterface $locker): bool
    {
        $modelKey = $this->getKeyPrefix() . $this->getModelIdentity($model);
        $modelLocker = $this->getRedis()->get($modelKey);

        if ($modelLocker === false) {
            return false;
        }

        return $modelLocker !== $this->getModelIdentity($locker);
    }

    /**
     * @inheritDoc
     */
    public function getLocker($modelOrReference): ?Reference
    {
        $modelKey = $this->getKeyPrefix() . $this->getModelIdentity($modelOrReference);
        $modelLocker = $this->getRedis()->get($modelKey);

        if ($modelLocker === false) {
            return null;
        }

        return Reference::fromJson($modelLocker);
    }

    /**
     * @inheritDoc
     */
    public function getDefaultTimeout(): int
    {
        return $this->defaultTimeout;
    }

    protected function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * @return string
     */
    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    /**
     * @return int
     */
    public function getMaxTimeout(): int
    {
        return $this->maxTimeout;
    }

    protected function getModelIdentity($modelOrReference): string
    {
        if ($modelOrReference instanceof Reference) {
            return json_encode($modelOrReference);
        }

        return json_encode(Reference::to($modelOrReference));
    }

    /**
     * @param int $timeout
     * @throws InvalidArgumentException
     */
    protected function guardTimeout(?int $timeout)
    {
        $timeout = $timeout ?? $this->getDefaultTimeout();
        if (1 > $timeout || $this->getMaxTimeout() < $timeout) {
            throw new InvalidArgumentException("Lock timeout should be between 1 and {$this->getMaxTimeout()} seconds");
        }
    }
}