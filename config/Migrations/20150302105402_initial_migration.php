<?php
use Phinx\Migration\AbstractMigration;

class InitialMigration extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('delayed_job_hosts');
        $table
            ->addColumn(
                'host_name',
                'string',
                [
                    'default' => null,
                    'limit' => 256,
                    'null' => false,
                ]
            )
            ->addColumn(
                'worker_name',
                'string',
                [
                    'default' => null,
                    'limit' => 32,
                    'null' => false,
                ]
            )
            ->addColumn(
                'pid',
                'integer',
                [
                    'default' => null,
                    'limit' => 10,
                    'null' => false,
                ]
            )
            ->addColumn(
                'created',
                'datetime',
                [
                    'default' => null,
                    'limit' => null,
                    'null' => false,
                ]
            )
            ->addColumn(
                'modified',
                'datetime',
                [
                    'default' => null,
                    'limit' => null,
                    'null' => false,
                ]
            )
            ->addColumn(
                'status',
                'integer',
                [
                    'default' => 1,
                    'limit' => 10,
                    'null' => false,
                ]
            )
            ->create();
        $table = $this->table('delayed_jobs');
        $table
            ->addColumn(
                'group',
                'string',
                [
                    'default' => null,
                    'limit' => 128,
                    'null' => true,
                ]
            )
            ->addColumn(
                'class',
                'string',
                [
                    'default' => null,
                    'limit' => 128,
                    'null' => false,
                ]
            )
            ->addColumn(
                'method',
                'string',
                [
                    'default' => null,
                    'limit' => 128,
                    'null' => false,
                ]
            )
            ->addColumn(
                'payload',
                'blob',
                [
                    'default' => null,
                    'limit' => null,
                    'null' => false,
                ]
            )
            ->addColumn(
                'options',
                'blob',
                [
                    'default' => null,
                    'limit' => null,
                    'null' => false,
                ]
            )
            ->addColumn(
                'status',
                'integer',
                [
                    'default' => null,
                    'limit' => 10,
                    'null' => false,
                ]
            )
            ->addColumn(
                'created',
                'datetime',
                [
                    'default' => null,
                    'limit' => null,
                    'null' => false,
                ]
            )
            ->addColumn(
                'modified',
                'datetime',
                [
                    'default' => null,
                    'limit' => null,
                    'null' => false,
                ]
            )
            ->addColumn(
                'retries',
                'integer',
                [
                    'default' => 0,
                    'limit' => 10,
                    'null' => false,
                ]
            )
            ->addColumn(
                'last_message',
                'string',
                [
                    'default' => null,
                    'limit' => 512,
                    'null' => true,
                ]
            )
            ->addColumn(
                'priority',
                'integer',
                [
                    'default' => 1,
                    'limit' => 10,
                    'null' => false,
                ]
            )
            ->addColumn(
                'run_at',
                'datetime',
                [
                    'default' => null,
                    'limit' => null,
                    'null' => false,
                ]
            )
            ->addColumn(
                'failed_at',
                'datetime',
                [
                    'default' => null,
                    'limit' => null,
                    'null' => true,
                ]
            )
            ->addColumn(
                'locked_by',
                'string',
                [
                    'default' => null,
                    'limit' => 128,
                    'null' => true,
                ]
            )
            ->addColumn(
                'pid',
                'integer',
                [
                    'default' => null,
                    'limit' => 10,
                    'null' => true,
                ]
            )
            ->create();
    }

    public function down()
    {
        $this->dropTable('delayed_jobs');
        $this->dropTable('delayed_job_hosts');
    }
}
