<?php
use Phinx\Migration\AbstractMigration;

class AddWorkerCountToDelayedJobHosts extends AbstractMigration
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
        $table = $this->table('delayed_job_hosts');
        $table->addColumn('worker_count', 'integer', [
            'default' => 1,
            'limit' => 11,
            'null' => false,
        ]);
        $table->update();
    }
}
