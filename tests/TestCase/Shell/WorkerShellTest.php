<?php
declare(strict_types=1);

namespace DelayedJobs\Test\TestCase\Shell;

use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use DelayedJobs\Model\Table\DelayedJobsTable;
use Fabricate\Fabricate;

/**
 * Class WorkerShellTest
 *
 * @coversDefaultClass DelayedJobs\Shell\WorkerShell
 */
class WorkerShellTest extends TestCase
{
    /**
     * fixtures
     *
     * @var array
     */
    public $fixtures = ['plugin.DelayedJobs.DelayedJobs'];

    /**
     * @var \DelayedJobs\Shell\WorkerShell
     */
    public $Shell;

    /**
     * @var \Cake\Console\ConsoleIo
     */
    public $io;

    /**
     * setup test
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();
        $this->io = $this->getMock('Cake\Console\ConsoleIo', [], [], '', false);
        $this->Shell = $this->getMock(
            'DelayedJobs\Shell\WorkerShell',
            ['in', 'out', 'hr', 'err', 'createFile', '_stop'],
            [$this->io]
        );
        $lock_mock = $this->getMock('\\DelayedJobs\\Lock');
        $this->Shell->startup($lock_mock);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    public function tearDown()
    {
        parent::tearDown();
        unset($this->Shell);
    }

    /**
     * @return void
     * @covers ::main
     * @expectedException \Cake\Core\Exception\Exception
     */
    public function testNoJobId()
    {
        $this->Shell->args = [];
        $this->Shell->main();
    }

    /**
     * @return void
     * @covers ::main
     */
    public function testStopsIfLocked()
    {
        $this->Shell->Lock
            ->expects($this->once())
            ->method('lock')
            ->with('DelayedJobs.WorkerShell.main.1')
            ->willReturn(false);

        $this->Shell
            ->expects($this->once())
            ->method('_stop')
            ->with(1);

        $this->Shell->args = [1];
        $this->Shell->main();
    }

    /**
     * @return void
     * @covers ::main
     */
    public function testStopsIfJobDoesNotExist()
    {
        $this->Shell->Lock
            ->expects($this->once())
            ->method('lock')
            ->with('DelayedJobs.WorkerShell.main.1')
            ->willReturn(true);

        $this->Shell
            ->expects($this->once())
            ->method('_stop')
            ->with(1);

        $this->Shell->args = [1];
        $this->Shell->main();
    }

    public function errorDataProvider()
    {
        return [
            [DelayedJobsTable::STATUS_SUCCESS, 'Job previously completed'],
            [DelayedJobsTable::STATUS_BURRIED, 'Job Failed too many times'],
        ];
    }

    /**
     * @param int $job_status Job status to use.
     * @param string $expected_message Expected message to test for.
     * @return void
     * @covers ::main
     * @dataProvider errorDataProvider
     */
    public function testErrorForStatus($job_status, $expected_message)
    {
        $job = Fabricate::create('DelayedJobs.DelayedJobs', 1, function () use ($job_status) {
            return ['status' => $job_status];
        })[0];

        $this->Shell->DelayedJobs = $this->getMockForModel('DelayedJobs.DelayedJobs', ['failed'], [
            'table' => 'delayed_jobs',
        ]);

        $this->Shell->Lock
            ->expects($this->once())
            ->method('lock')
            ->with('DelayedJobs.WorkerShell.main.1')
            ->willReturn(true);

        $this->Shell->DelayedJobs
            ->expects($this->once())
            ->method('failed')
            ->with(
                $this->callback(function ($subject) use ($job) {
                    return $subject instanceof Entity && $subject->id === $job->id;
                }),
                $this->stringContains($expected_message)
            );

        $this->Shell->args = [$job->id];
        $this->Shell->main();
    }

    protected function _buildJobMock()
    {
        $job_data = Fabricate::attributes_for('DelayedJobs.DelayedJobs', 1, function () {
            return ['status' => DelayedJobsTable::STATUS_BUSY];
        })[0];

        $job = $this->getMock('\\DelayedJobs\\Model\\Entity\\DelayedJob', ['execute'], [$job_data]);

        $this->Shell->DelayedJobs = $this->getMockForModel('DelayedJobs.DelayedJobs', ['failed', 'completed', 'get'], [
            'table' => 'delayed_jobs',
        ]);

        $this->Shell->Lock
            ->expects($this->once())
            ->method('lock')
            ->with('DelayedJobs.WorkerShell.main.1')
            ->willReturn(true);

        $this->Shell->DelayedJobs
            ->expects($this->once())
            ->method('get')
            ->with($job->id)
            ->willReturn($job);

        return $job;
    }

    /**
     * @return void
     * @covers ::main
     */
    public function testExecuteSuccessJob()
    {
        $job = $this->_buildJobMock();

        $job
            ->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $this->Shell->DelayedJobs
            ->expects($this->once())
            ->method('completed')
            ->with(
                $this->callback(function ($subject) use ($job) {
                    return $subject instanceof Entity && $subject->id === $job->id;
                })
            );

        $this->Shell->args = [$job->id];
        $this->Shell->main();
    }

    /**
     * @return void
     * @covers ::main
     */
    public function testExecuteFailedJob()
    {
        $job = $this->_buildJobMock();

        $job
            ->expects($this->once())
            ->method('execute')
            ->willReturn(false);

        $this->Shell->DelayedJobs
            ->expects($this->once())
            ->method('failed')
            ->with(
                $this->callback(function ($subject) use ($job) {
                    return $subject instanceof Entity && $subject->id === $job->id;
                }),
                $this->stringContains('Invalid response received')
            );

        $this->Shell->args = [$job->id];
        $this->Shell->main();
    }
}
