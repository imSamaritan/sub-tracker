<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRateLimitsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('rate_limits', [
            'id' => false,
            'primary_key' => ['bucket', 'window_start'],
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'API rate-limit counters'
        ]);

        $table->addColumn('bucket', 'string', ['limit' => 100, 'null' => false])
              ->addColumn('window_start', 'datetime', ['null' => false, 'comment' => 'UTC start of the fixed window'])
              ->addColumn('hits', 'integer', ['signed' => false, 'default' => 0])
              ->addIndex(['window_start'], ['name' => 'idx_rate_limits_window'])
              ->create();
    }

    public function down(): void
    {
        $this->table('rate_limits')->drop()->save();
    }
}
