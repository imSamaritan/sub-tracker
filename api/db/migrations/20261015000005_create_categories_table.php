<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCategoriesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('categories', [
            'id' => false,
            'primary_key' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Subscription categories'
        ]);

        $table->addColumn('id', 'integer', ['identity' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'signed' => false])
              ->addColumn('code', 'string', ['limit' => 50, 'null' => false])
              ->addColumn('label', 'string', ['limit' => 100, 'null' => false])
              ->addColumn('default_icon', 'string', ['limit' => 50, 'default' => 'fa-tags'])
              ->addColumn('default_color', 'char', ['limit' => 7, 'default' => '#6366f1'])
              ->addColumn('sort_order', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'signed' => false, 'default' => 0])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['code'], ['unique' => true, 'name' => 'uq_categories_code'])
              ->create();
    }

    public function down(): void
    {
        $this->table('categories')->drop()->save();
    }
}
