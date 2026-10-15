<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePasswordResetsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('password_resets', [
            'id' => false,
            'primary_key' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Password reset tokens (hashed)'
        ]);

        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => false])
              ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'SHA-256 hex of plain reset token'])
              ->addColumn('expires_at', 'datetime', ['null' => false])
              ->addColumn('used_at', 'datetime', ['null' => true])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_password_resets_hash'])
              ->addIndex(['user_id', 'expires_at'], ['name' => 'idx_password_resets_user_expires'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_password_resets_user'])
              ->create();
    }

    public function down(): void
    {
        $this->table('password_resets')->drop()->save();
    }
}
