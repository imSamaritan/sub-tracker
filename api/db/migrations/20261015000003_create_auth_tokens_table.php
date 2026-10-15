<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateAuthTokensTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('auth_tokens', [
            'id' => false,
            'primary_key' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Active authentication bearer tokens'
        ]);

        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => false])
              ->addColumn('token_hash', 'char', ['limit' => 64, 'null' => false, 'comment' => 'SHA-256 hex of plain bearer token'])
              ->addColumn('device_name', 'string', ['limit' => 100, 'null' => true])
              ->addColumn('last_used_at', 'datetime', ['null' => true])
              ->addColumn('expires_at', 'datetime', ['null' => false])
              ->addColumn('revoked_at', 'datetime', ['null' => true])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_auth_tokens_hash'])
              ->addIndex(['user_id', 'expires_at'], ['name' => 'idx_auth_tokens_user_expires'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_auth_tokens_user'])
              ->create();
    }

    public function down(): void
    {
        $this->table('auth_tokens')->drop()->save();
    }
}
