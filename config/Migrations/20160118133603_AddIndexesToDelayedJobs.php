<?php
use Migrations\AbstractMigration;

class AddIndexesToDelayedJobs extends AbstractMigration
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
        $table
            ->addIndex('status')
            ->addIndex('created')
            ->addIndex('modified');
        $table->update();
    }
}
