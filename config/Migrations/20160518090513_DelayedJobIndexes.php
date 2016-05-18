<?php
use Migrations\AbstractMigration;

class DelayedJobIndexes extends AbstractMigration
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
        $this->table('delayed_job_workers')
            ->addIndex(['host_name', 'status'])
            ->update();

        $this->table('delayed_jobs')
            ->addIndex(['worker', 'status'])
            ->addIndex(['status', 'sequence'])
            ->addIndex(['priority', 'id'])
            ->removeIndex('created')
            ->removeIndex('modified')
            ->update();
    }
}
