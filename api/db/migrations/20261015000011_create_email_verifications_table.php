<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEmailVerificationsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('email_verifications', [
            'id' => false,
            'primary_key' => 'id',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'comment' => 'Email verification tokens (hashed)'
        ]);

        $table->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
              ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => false])
              ->addColumn('email', 'string', ['limit' => 191, 'null' => false])
              ->addColumn('token_hash', 'char', ['limit' => 64, 'comment' => 'SHA-256 hex of the plain token'])
              ->addColumn('expires_at', 'datetime', ['null' => false])
              ->addColumn('used_at', 'datetime', ['null' => true])
              ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_email_verifications_hash'])
              ->addIndex(['user_id', 'created_at'], ['name' => 'idx_email_verifications_user'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION', 'constraint' => 'fk_email_verifications_user'])
              ->create();
    }

    public function down(): void
    {
        $this->table('email_verifications')->drop()->save();
    }
}
