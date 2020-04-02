<?php
/**
 * Created for locker-redis
 * Date: 02.04.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace DiBify\Locker\Redis;

use DiBify\DiBify\Exceptions\InvalidArgumentException;
use DiBify\DiBify\Id\Id;
use DiBify\DiBify\Model\Link;
use DiBify\DiBify\Model\ModelInterface;
use PHPUnit\Framework\TestCase;
use Redis;

class LockerTest extends TestCase
{

    /** @var Redis */
    private $redis;

    /** @var string */
    private $prefix;

    /** @var int */
    private $defaultTimeout;

    /** @var int */
    private $maxTimeout;

    /** @var Locker */
    private $locker;

    /** @var ModelInterface */
    private $model_1;

    /** @var ModelInterface */
    private $model_2;

    /** @var ModelInterface */
    private $model_3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->select(0);

        $this->prefix = 'Locker:';
        $this->defaultTimeout = 2;
        $this->maxTimeout = 100;

        $keys = $this->redis->keys($this->prefix . '*');
        foreach ($keys as $key) {
            $this->redis->del($key);
        }

        $this->locker = new Locker(
            $this->redis,
            $this->prefix,
            $this->defaultTimeout,
            $this->maxTimeout,
        );

        $this->model_1 = new class () implements ModelInterface {

            public function id(): Id
            {
                return new Id(1);
            }

            public static function getModelAlias(): string
            {
                return 'model_1';
            }
        };

        $this->model_2 = new class () implements ModelInterface {

            public function id(): Id
            {
                return new Id(2);
            }

            public static function getModelAlias(): string
            {
                return 'model_2';
            }
        };

        $this->model_3 = new class () implements ModelInterface {

            public function id(): Id
            {
                return new Id(3);
            }

            public static function getModelAlias(): string
            {
                return 'model_3';
            }
        };
    }

    public function testLock()
    {
        $this->assertTrue($this->locker->lock($this->model_1, $this->model_2));
        $this->assertTrue($this->locker->lock($this->model_1, $this->model_2));
        $this->assertFalse($this->locker->lock($this->model_1, $this->model_3));


        $this->assertTrue($this->locker->lock($this->model_1, $this->model_2, 1));
        usleep(1200000);
        $this->assertTrue($this->locker->lock($this->model_1, $this->model_3));
    }

    public function testLockWithZeroTimeout()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->locker->lock($this->model_1, $this->model_2, 0);
    }

    public function testLockWithTooBigTimeout()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->locker->lock($this->model_1, $this->model_2, 1000);
    }

    public function testUnlock()
    {
        $this->assertTrue($this->locker->unlock($this->model_1, $this->model_2));
        $this->assertTrue($this->locker->lock($this->model_1, $this->model_2));
        $this->assertFalse($this->locker->unlock($this->model_1, $this->model_3));
        $this->assertTrue($this->locker->unlock($this->model_1, $this->model_2));
    }

    public function testPassLock()
    {
        $this->assertTrue($this->locker->passLock($this->model_1, $this->model_2, $this->model_3));
        $this->assertTrue($this->locker->passLock($this->model_1, $this->model_3, $this->model_2));
        $this->assertFalse($this->locker->passLock($this->model_1, $this->model_3, $this->model_2));
    }

    public function testPassLockWithZeroTimeout()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->locker->passLock($this->model_1, $this->model_2, $this->model_3, 0);
    }

    public function testPassLockWithTooBigTimeout()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->locker->passLock($this->model_1, $this->model_2, $this->model_3, 1000);
    }

    public function testIsLockedFor()
    {
        $this->locker->lock($this->model_1, $this->model_2);

        $this->assertTrue($this->locker->isLockedFor($this->model_1, $this->model_3));
        $this->assertFalse($this->locker->isLockedFor($this->model_1, $this->model_2));
        $this->assertFalse($this->locker->isLockedFor($this->model_2, $this->model_3));
    }

    public function testGetLocker()
    {
        $this->locker->lock($this->model_1, $this->model_2);

        $lockerLink = $this->locker->getLocker($this->model_1);
        $this->assertInstanceOf(Link::class, $lockerLink);
        $this->assertSame($this->model_2, $lockerLink->getModel());

        $this->assertNull($this->locker->getLocker($this->model_2));
    }

    public function testGetDefaultTimeout()
    {
        $this->assertSame(
            $this->defaultTimeout,
            $this->locker->getDefaultTimeout()
        );
    }

    public function testGetMaxTimeout()
    {
        $this->assertSame(
            $this->maxTimeout,
            $this->locker->getMaxTimeout()
        );
    }

    public function testGetKeyPrefix()
    {
        $this->assertSame(
            $this->prefix,
            $this->locker->getKeyPrefix()
        );
    }
}
