<?php
use Migrations\AbstractMigration;

class ChangePayloadDataTypeToLongForDelayedJobs extends AbstractMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-change-method
     * @return void
     */
    public function up()
    {
        $this->table('delayed_jobs')
            ->changeColumn('payload', 'blob', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::BLOB_LONG
            ])
            ->update();
    }

    public function down()
    {
        $this->table('delayed_jobs')
            ->changeColumn('payload', 'blob', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::BLOB_MEDIUM
            ])
            ->update();
    }

}
