<?php
declare(strict_types=1);

namespace DelayedJobs\Test\TestCase;

use Cake\Filesystem\Folder;
use Cake\TestSuite\TestCase;

/**
 * Class LockTest
 *
 * @coversDefaultClass \DelayedJobs\Lock
 */
class LockTest extends TestCase
{
    /**
     * @var \DelayedJobs\Lock
     */
    public $Lock;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->Lock = $this->getMock('\\DelayedJobs\\Lock', ['running']);
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        parent::tearDown();
        $dir = new Folder(TMP . 'lock');
        $dir->delete();
        unset($this->Lock);
    }

    /**
     * @return void
     * @covers ::lock
     */
    public function testLock()
    {
        $file_path = TMP . 'lock' . DS . 'test-lock';

        $this->assertFalse(file_exists($file_path));
        $this->Lock->lock('test-lock');
        $this->assertTrue(file_exists($file_path));
        $contents = file_get_contents($file_path);
        $contents = json_decode($contents, true);
        $this->assertSame(0, json_last_error());
        //Within 10 seconds
        $this->assertWithinRange(time(), $contents['time'], 10);
    }

    /**
     * @return void
     * @covers ::unlock
     */
    public function testUnlock()
    {
        mkdir(TMP . 'lock');
        $file_path = TMP . 'lock' . DS . 'test-lock';
        $this->assertFalse(file_exists($file_path));
        touch($file_path);
        $this->assertTrue(file_exists($file_path));

        $this->Lock->unlock('test-lock');
        $this->assertFalse(file_exists($file_path));
    }

    /**
     * @return void
     * @covers ::lock
     */
    public function testAlreadyLockedNotRunning()
    {
        $file_path = TMP . 'lock' . DS . 'test-lock';

        $this->assertFalse(file_exists($file_path));
        $this->Lock->lock('test-lock');
        $this->assertTrue(file_exists($file_path));

        $this->Lock
            ->expects($this->once())
            ->method('running')
            ->will($this->returnValue(false));

        $this->assertTrue($this->Lock->lock('test-lock'));
    }

    /**
     * @return void
     * @covers ::lock
     */
    public function testAlreadyLockedRunning()
    {
        $file_path = TMP . 'lock' . DS . 'test-lock';

        $this->assertFalse(file_exists($file_path));
        $this->Lock->lock('test-lock');
        $this->assertTrue(file_exists($file_path));

        $this->Lock
            ->expects($this->once())
            ->method('running')
            ->will($this->returnValue(true));

        $this->assertFalse($this->Lock->lock('test-lock'));
    }
}
