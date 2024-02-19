<?php
/**
 * Created for locker-redis
 * Date: 31.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace DiBify\Locker\Redis;


use DiBify\DiBify\Exceptions\InvalidArgumentException;
use DiBify\DiBify\Locker\Lock\Lock;
use DiBify\DiBify\Locker\LockerInterface;
use DiBify\DiBify\Locker\WaitForLockTrait;
use DiBify\DiBify\Model\Reference;
use DiBify\DiBify\Model\ModelInterface;
use Redis;
use RedisException;
use Throwable;

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
     * @param ModelInterface $model
     * @param Lock $lock
     * @param Throwable|null $throwable
     * @return bool
     * @throws InvalidArgumentException
     * @throws Throwable
     * @throws RedisException
     */
    public function lock(ModelInterface $model, Lock $lock, ?Throwable $throwable = null): bool
    {
        $this->guardTimeout($lock);

        $modelKey = $this->getKeyPrefix() . $this->getModelKey($model);
        $modelLocker = $this->getLockKey($lock);

        if ($this->getRedis()->setnx($modelKey, $modelLocker)) {
            $this->getRedis()->expire($modelKey, $lock->getTimeout() ?? $this->getDefaultTimeout());
            return true;
        }

        if ($this->getLock($model)?->isCompatible($lock)) {
            $this->getRedis()->expire($modelKey, $lock->getTimeout() ?? $this->getDefaultTimeout());
            return true;
        }

        if ($throwable) {
            throw $throwable;
        }

        return false;
    }

    /**
     * @param ModelInterface $model
     * @param Lock $lock
     * @param Throwable|null $throwable
     * @return bool
     * @throws RedisException
     * @throws Throwable
     */
    public function unlock(ModelInterface $model, Lock $lock, ?Throwable $throwable = null): bool
    {
        $modelKey = $this->getKeyPrefix() . $this->getModelKey($model);

        $actualLock = $this->getLock($model);

        if (!$actualLock) {
            return true;
        }

        if ($actualLock->isCompatible($lock)) {
            $this->getRedis()->del($modelKey);
            return true;
        }

        if ($throwable) {
            throw $throwable;
        }

        return false;
    }

    /**
     * @param ModelInterface $model
     * @param Lock $currentLock
     * @param Lock $lock
     * @param Throwable|null $throwable
     * @return bool
     * @throws InvalidArgumentException
     * @throws RedisException
     * @throws Throwable
     */
    public function passLock(ModelInterface $model, Lock $currentLock, Lock $lock, ?Throwable $throwable = null): bool
    {
        $this->guardTimeout($lock);
        $actualLock = $this->getLock($model);

        if (!$actualLock) {
            return $this->lock($model, $lock, $throwable);
        }

        if ($actualLock->isCompatible($currentLock)) {
            $modelKey = $this->getKeyPrefix() . $this->getModelKey($model);
            $this->getRedis()->set($modelKey, $this->getLockKey($lock), $lock->getTimeout() ?? $this->defaultTimeout);
            return true;
        }

        if ($throwable) {
            throw $throwable;
        }

        return false;
    }

    public function isLockedFor(ModelInterface|Reference $model, Lock $lock): bool
    {
        if (!$actualLock = $this->getLock($model)) {
            return false;
        }

        return !$actualLock->isCompatible($lock);
    }


    public function getLock(ModelInterface|Reference $model): ?Lock
    {
        $modelRef = $model instanceof ModelInterface ? Reference::to($model) : $model;

        $modelKey = $this->getKeyPrefix() . $this->getModelKey($modelRef);
        $modelLocker = $this->getRedis()->get($modelKey);

        if (!$modelLocker) {
            return null;
        }

        $lockerData = json_decode($modelLocker, true);
        $lockerTimeout = $this->redis->ttl($modelKey);

        $reference = Reference::fromArray($lockerData['locker']);
        return new Lock(
            locker: $reference,
            identity: $lockerData['identity'],
            timeout: $lockerTimeout > 0 ? $lockerTimeout : null
        );
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

    protected function getModelKey($modelOrReference): string
    {
        if ($modelOrReference instanceof Reference) {
            return json_encode($modelOrReference);
        }

        return json_encode(Reference::to($modelOrReference));
    }

    protected function getLockKey(Lock $lock): string
    {
        return json_encode($lock);
    }

    /**
     * @param Lock $lock
     * @throws InvalidArgumentException
     */
    protected function guardTimeout(Lock $lock)
    {
        $timeout = $lock->getTimeout() ?? $this->getDefaultTimeout();
        if (1 > $timeout || $this->getMaxTimeout() < $timeout) {
            throw new InvalidArgumentException("Lock timeout should be between 1 and {$this->getMaxTimeout()} seconds");
        }
    }
}