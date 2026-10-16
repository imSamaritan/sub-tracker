<?php
declare(strict_types=1);

namespace SubTracker\Repositories;

use PDO;

class CategoryRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    /**
     * Retrieve all categories sorted by sort_order.
     *
     * @return array<int, array{code: string, label: string, defaultIcon: string, defaultColor: string, sortOrder: int}>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, code, label, default_icon, default_color, sort_order
            FROM categories
            ORDER BY sort_order ASC, id ASC
        ");

        $rows = $stmt->fetchAll();
        return array_map(fn(array $row): array => [
            'code' => $row['code'],
            'label' => $row['label'],
            'defaultIcon' => $row['default_icon'],
            'defaultColor' => $row['default_color'],
            'sortOrder' => (int) $row['sort_order'],
        ], $rows);
    }
}
