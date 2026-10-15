<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePaymentsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('payments', [
            'id' => false,
            'primary_key' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Payment history ledger'
        ]);

        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => false])
              ->addColumn('subscription_id', 'biginteger', ['signed' => false, 'null' => true])
              ->addColumn('subscription_name', 'string', ['limit' => 100, 'null' => false, 'comment' => 'Snapshot of name at payment time'])
              ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => false, 'comment' => 'Snapshot of amount at payment time'])
              ->addColumn('cycle_due_date', 'date', ['null' => false])
              ->addColumn('paid_date', 'date', ['null' => false])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['user_id', 'paid_date'], ['name' => 'idx_payments_user_paid_date'])
              ->addIndex(['subscription_id', 'cycle_due_date'], ['name' => 'idx_payments_sub_cycle_due'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_payments_user'])
              ->addForeignKey('subscription_id', 'subscriptions', 'id', ['delete' => 'SET NULL', 'update' => 'NO_ACTION', 'constraint' => 'fk_payments_subscription'])
              ->create();
    }

    public function down(): void
    {
        $this->table('payments')->drop()->save();
    }
}
