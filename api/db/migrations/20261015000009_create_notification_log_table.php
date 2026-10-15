<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateNotificationLogTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('notification_log', [
            'id' => false,
            'primary_key' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Deduplication log for notifications and reminders'
        ]);

        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => false])
              ->addColumn('subscription_id', 'biginteger', ['signed' => false, 'null' => false])
              ->addColumn('cycle_due_date', 'date', ['null' => false])
              ->addColumn('alert_type', 'enum', ['values' => ['overdue', 'due_today', 'upcoming'], 'null' => false])
              ->addColumn('channel', 'enum', ['values' => ['push', 'email'], 'default' => 'push'])
              ->addColumn('sent_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['user_id', 'subscription_id', 'cycle_due_date', 'alert_type', 'channel'], ['unique' => true, 'name' => 'uq_notification_log_dedup'])
              ->addIndex(['user_id', 'sent_at'], ['name' => 'idx_notification_log_user_sent'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_notification_log_user'])
              ->addForeignKey('subscription_id', 'subscriptions', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_notification_log_sub'])
              ->create();
    }

    public function down(): void
    {
        $this->table('notification_log')->drop()->save();
    }
}
