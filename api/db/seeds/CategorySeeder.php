<?php
declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class CategorySeeder extends AbstractSeed
{
    public function run(): void
    {
        $categories = [
            ['code' => 'Entertainment', 'label' => 'Entertainment', 'default_icon' => 'fa-tv',            'default_color' => '#0084f7', 'sort_order' => 1],
            ['code' => 'Music',         'label' => 'Music',         'default_icon' => 'fa-music',         'default_color' => '#1db954', 'sort_order' => 2],
            ['code' => 'Internet',      'label' => 'Internet',      'default_icon' => 'fa-wifi',          'default_color' => '#0ea5e9', 'sort_order' => 3],
            ['code' => 'Telco',         'label' => 'Telco',         'default_icon' => 'fa-mobile-screen', 'default_color' => '#f59e0b', 'sort_order' => 4],
            ['code' => 'Fitness',       'label' => 'Fitness',       'default_icon' => 'fa-dumbbell',      'default_color' => '#ef4444', 'sort_order' => 5],
            ['code' => 'Health',        'label' => 'Health',        'default_icon' => 'fa-heart-pulse',   'default_color' => '#10b981', 'sort_order' => 6],
            ['code' => 'Cloud',         'label' => 'Cloud',         'default_icon' => 'fa-cloud',         'default_color' => '#38bdf8', 'sort_order' => 7],
            ['code' => 'Software',      'label' => 'Software',      'default_icon' => 'fa-laptop-code',   'default_color' => '#8b5cf6', 'sort_order' => 8],
            ['code' => 'Insurance',     'label' => 'Insurance',     'default_icon' => 'fa-shield-halved', 'default_color' => '#6366f1', 'sort_order' => 9],
            ['code' => 'Housing',       'label' => 'Housing',       'default_icon' => 'fa-house',         'default_color' => '#f97316', 'sort_order' => 10],
            ['code' => 'Food',          'label' => 'Food',          'default_icon' => 'fa-utensils',      'default_color' => '#84cc16', 'sort_order' => 11],
            ['code' => 'Family',        'label' => 'Family',        'default_icon' => 'fa-people-roof',   'default_color' => '#ec4899', 'sort_order' => 12],
            ['code' => 'Savings',       'label' => 'Savings',       'default_icon' => 'fa-piggy-bank',    'default_color' => '#14b8a6', 'sort_order' => 13],
            ['code' => 'Other',         'label' => 'Other',         'default_icon' => 'fa-tags',          'default_color' => '#6b7280', 'sort_order' => 14],
        ];

        $stmt = $this->getAdapter()->getConnection()->prepare(
            'INSERT INTO categories (code, label, default_icon, default_color, sort_order)
             VALUES (:code, :label, :default_icon, :default_color, :sort_order)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                default_icon = VALUES(default_icon),
                default_color = VALUES(default_color),
                sort_order = VALUES(sort_order)'
        );

        foreach ($categories as $cat) {
            $stmt->execute($cat);
        }
    }
}
