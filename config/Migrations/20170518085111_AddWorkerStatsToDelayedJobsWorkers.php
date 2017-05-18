<?php
use Migrations\AbstractMigration;

class AddWorkerStatsToDelayedJobsWorkers extends AbstractMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-change-method
     * @return void
     */
    public function change()
    {
        $table = $this->table('delayed_job_workers');
        $table->addColumn('last_job', 'integer', [
            'default' => null,
            'null' => true,
        ]);
        $table->addColumn('memory_usage', 'integer', [
            'default' => 0,
            'null' => false,
        ]);
        $table->addColumn('idle_time', 'integer', [
            'default' => 0,
            'null' => false,
        ]);
        $table->addColumn('shutdown_reason', 'string', [
            'default' => null,
            'limit' => 25,
            'null' => true,
        ]);
        $table->addColumn('shutdown_time', 'datetime', [
            'default' => null,
            'null' => true,
        ]);
        $table->update();
    }
}
