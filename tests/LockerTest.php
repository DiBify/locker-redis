<?php
/**
 * Created for locker-redis
 * Date: 02.04.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace DiBify\Locker\Redis;

use DiBify\DiBify\Exceptions\InvalidArgumentException;
use DiBify\DiBify\Id\Id;
use DiBify\DiBify\Locker\Lock\Lock;
use DiBify\DiBify\Model\ModelInterface;
use DiBify\DiBify\Model\Reference;
use PHPUnit\Framework\TestCase;
use Redis;

class LockerTest extends TestCase
{

    private Redis $redis;

    private string $prefix;

    private int $defaultTimeout;

    protected int $maxTimeout;

    protected Locker $locker;

    private ModelInterface $model_1;

    private ModelInterface $model_2;

    private ModelInterface $model_3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new Redis();
        $this->redis->connect('localhost', 6379);
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

    public function testLock(): void
    {
        $this->assertTrue($this->locker->lock($this->model_1, new Lock($this->model_2)));
        $this->assertTrue($this->locker->lock($this->model_1, new Lock($this->model_2)));
        $this->assertFalse($this->locker->lock($this->model_1, new Lock($this->model_3)));
        $this->assertFalse($this->locker->lock($this->model_1, new Lock($this->model_2, 'with_identity')));


        $this->assertTrue($this->locker->lock($this->model_1, new Lock($this->model_2, null, 1)));
        usleep(1200000);
        $this->assertTrue($this->locker->lock($this->model_1, new Lock($this->model_3)));
    }

    public function testLockWithZeroTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->locker->lock($this->model_1, new Lock($this->model_2, null, 0));
    }

    public function testLockWithTooBigTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->locker->lock($this->model_1, new Lock($this->model_2, null, 1000));
    }

    public function testUnlock(): void
    {
        $this->assertTrue($this->locker->unlock($this->model_1, new Lock($this->model_2)));
        $this->assertTrue($this->locker->lock($this->model_1, new Lock($this->model_2)));
        $this->assertFalse($this->locker->unlock($this->model_1, new Lock($this->model_3)));
        $this->assertFalse($this->locker->unlock($this->model_1, new Lock($this->model_2, 'with_identity')));
        $this->assertTrue($this->locker->unlock($this->model_1, new Lock($this->model_2)));
    }

    public function testPassLock(): void
    {
        $this->assertTrue($this->locker->passLock($this->model_1, new Lock($this->model_2), new Lock($this->model_3)));
        $this->assertTrue($this->locker->passLock($this->model_1, new Lock($this->model_3), new Lock($this->model_2)));
        $this->assertFalse($this->locker->passLock($this->model_1, new Lock($this->model_2, 'with_identity'), new Lock($this->model_3)));
        $this->assertFalse($this->locker->passLock($this->model_1, new Lock($this->model_3), new Lock($this->model_2)));
    }

    public function testPassLockWithZeroTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->locker->passLock($this->model_1, new Lock($this->model_2), new Lock($this->model_3, null, 0));
    }

    public function testPassLockWithTooBigTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->locker->passLock($this->model_1, new Lock($this->model_2), new Lock($this->model_3, null, 1000));
    }

    public function testIsLockedFor(): void
    {
        $this->locker->lock($this->model_1, new Lock($this->model_2));

        $this->assertTrue($this->locker->isLockedFor($this->model_1, new Lock($this->model_3)));
        $this->assertTrue($this->locker->isLockedFor($this->model_1, new Lock($this->model_2, 'with_identity')));
        $this->assertFalse($this->locker->isLockedFor($this->model_1, new Lock($this->model_2)));
        $this->assertFalse($this->locker->isLockedFor($this->model_2, new Lock($this->model_3)));
    }

    public function testGetLock(): void
    {
        $lock = new Lock($this->model_2, null, 10);
        $this->locker->lock($this->model_1, $lock);

        sleep(2);
        $actualLock = $this->locker->getLock($this->model_1);
        $this->assertInstanceOf(Lock::class, $actualLock);
        $this->assertTrue($actualLock->isCompatible($lock));
        $this->assertEquals(8, $actualLock->getTimeout());

        $this->assertNull($this->locker->getLock($this->model_2));
    }

    public function testGetDefaultTimeout(): void
    {
        $this->assertSame(
            $this->defaultTimeout,
            $this->locker->getDefaultTimeout()
        );
    }

    public function testGetMaxTimeout(): void
    {
        $this->assertSame(
            $this->maxTimeout,
            $this->locker->getMaxTimeout()
        );
    }

    public function testGetKeyPrefix(): void
    {
        $this->assertSame(
            $this->prefix,
            $this->locker->getKeyPrefix()
        );
    }
}
