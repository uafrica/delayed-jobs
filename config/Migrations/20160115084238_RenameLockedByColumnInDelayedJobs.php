<?php
use Migrations\AbstractMigration;

class RenameLockedByColumnInDelayedJobs extends AbstractMigration
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
        $table = $this->table('delayed_jobs');
        $table->renameColumn('locked_by', 'host_name');
        $table->update();
    }
}
