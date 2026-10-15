<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSubscriptionsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('subscriptions', [
            'id' => false,
            'primary_key' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'User recurring subscriptions'
        ]);

        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => false])
              ->addColumn('category_id', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'signed' => false, 'null' => false])
              ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
              ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => false])
              ->addColumn('billing_cycle', 'enum', ['values' => ['weekly', 'monthly', 'yearly'], 'default' => 'monthly'])
              ->addColumn('due_date', 'date', ['null' => false])
              ->addColumn('billing_day', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'signed' => false, 'null' => true, 'comment' => 'Anchor day of month for renewal clamping'])
              ->addColumn('start_date', 'date', ['null' => true])
              ->addColumn('icon', 'string', ['limit' => 50, 'default' => 'fa-receipt'])
              ->addColumn('color', 'char', ['limit' => 7, 'default' => '#6366f1'])
              ->addColumn('notes', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('is_paid_this_cycle', 'boolean', ['default' => false])
              ->addColumn('status', 'enum', ['values' => ['active', 'paused'], 'default' => 'active'])
              ->addColumn('deleted_at', 'datetime', ['null' => true])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['user_id', 'deleted_at', 'is_paid_this_cycle', 'due_date'], ['name' => 'idx_subscriptions_user_status_due'])
              ->addIndex(['user_id', 'status'], ['name' => 'idx_subscriptions_user_status'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_subscriptions_user'])
              ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'RESTRICT', 'update' => 'NO_ACTION', 'constraint' => 'fk_subscriptions_category'])
              ->create();
    }

    public function down(): void
    {
        $this->table('subscriptions')->drop()->save();
    }
}
