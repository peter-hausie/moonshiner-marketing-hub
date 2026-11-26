<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../scripts/rehearsal/rows.php';

class RowManifestTest extends TestCase
{
    public function test_manifest_preserves_nulls_duplicates_and_identity_fields_without_row_output(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER, role TEXT, password TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1, 'curator', 'private-hash'), (2, NULL, '')");
        $template = ['users' => ['columns' => ['id', 'role', 'password']]];
        $original = rowManifest($pdo, $template);
        $this->assertSame(2, $original['users']['count']);
        $this->assertStringNotContainsString('private-hash', json_encode($original));
        $pdo->exec("UPDATE users SET role = '' WHERE id = 2");
        $this->assertNotSame($original, rowManifest($pdo, $template));
        $pdo->exec('UPDATE users SET role = NULL WHERE id = 2');
        $this->assertSame($original, rowManifest($pdo, $template));
        $pdo->exec("UPDATE users SET role = 'contributor' WHERE id = 1");
        $this->assertNotSame($original, rowManifest($pdo, $template));
        $pdo->exec("UPDATE users SET role = 'curator' WHERE id = 1");
        $pdo->exec('INSERT INTO users SELECT * FROM users WHERE id = 1');
        $this->assertNotSame($original, rowManifest($pdo, $template));
    }

    public function test_original_columns_are_order_independent_but_new_columns_are_explicitly_ignored(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE publications (id INTEGER, status TEXT)');
        $pdo->exec("INSERT INTO publications VALUES (2, 'pending'), (1, 'published')");
        $template = ['publications' => ['columns' => ['id', 'status']]];
        $original = rowManifest($pdo, $template);
        $pdo->exec('DELETE FROM publications');
        $pdo->exec("INSERT INTO publications VALUES (1, 'published'), (2, 'pending')");
        $this->assertSame($original, rowManifest($pdo, $template));
        $pdo->exec('ALTER TABLE publications ADD COLUMN content_version_id INTEGER');
        $pdo->exec('UPDATE publications SET content_version_id = 42');
        $this->assertSame($original, rowManifest($pdo, $template));
        $pdo->exec("UPDATE publications SET status = 'failed' WHERE id = 2");
        $this->assertNotSame($original, rowManifest($pdo, $template));
    }
}
