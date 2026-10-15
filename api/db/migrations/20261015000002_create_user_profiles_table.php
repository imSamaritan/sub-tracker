<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUserProfilesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('user_profiles', [
            'id' => false,
            'primary_key' => 'user_id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'User profile and preferences'
        ]);

        $table->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => false])
              ->addColumn('monthly_salary', 'decimal', ['precision' => 12, 'scale' => 2, 'default' => '0.00'])
              ->addColumn('salary_day', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'signed' => false, 'null' => true, 'default' => 25])
              ->addColumn('currency', 'char', ['limit' => 3, 'default' => 'ZAR'])
              ->addColumn('currency_symbol', 'string', ['limit' => 5, 'default' => 'R'])
              ->addColumn('theme', 'enum', ['values' => ['dark', 'light'], 'default' => 'dark'])
              ->addColumn('system_notifications_enabled', 'boolean', ['default' => false])
              ->addColumn('timezone', 'string', ['limit' => 50, 'default' => 'Africa/Johannesburg'])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_user_profiles_user'])
              ->create();
    }

    public function down(): void
    {
        $this->table('user_profiles')->drop()->save();
    }
}
