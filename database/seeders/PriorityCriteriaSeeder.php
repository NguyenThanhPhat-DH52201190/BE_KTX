<?php

namespace Database\Seeders;

use App\Models\PriorityCriteria;
use Illuminate\Database\Seeder;

class PriorityCriteriaSeeder extends Seeder
{
    /**
     * Seed the 6 policy priority criteria.
     *
     * `tier` is fixed by policy (1 = highest). `priority_score` is a
     * configurable value that admin may adjust later.
     */
    public function run(): void
    {
        $criteria = [
            ['code' => 'UT01', 'tier' => 1, 'priority_score' => 100, 'name' => 'Con liệt sĩ / thương binh / bệnh binh'],
            ['code' => 'UT02', 'tier' => 1, 'priority_score' => 90,  'name' => 'Con người hoạt động kháng chiến bị nhiễm chất độc hóa học'],
            ['code' => 'UT03', 'tier' => 2, 'priority_score' => 85,  'name' => 'Khuyết tật / mồ côi cả cha lẫn mẹ'],
            ['code' => 'UT04', 'tier' => 2, 'priority_score' => 80,  'name' => 'Dân tộc thiểu số'],
            ['code' => 'UT05', 'tier' => 2, 'priority_score' => 75,  'name' => 'Hộ nghèo / cận nghèo / xã khó khăn'],
            ['code' => 'UT06', 'tier' => 3, 'priority_score' => 30,  'name' => 'Công tác xã hội (đoàn/hội)'],
        ];

        foreach ($criteria as $item) {
            PriorityCriteria::updateOrCreate(
                ['code' => $item['code']],
                [
                    'name' => $item['name'],
                    'tier' => $item['tier'],
                    'priority_score' => $item['priority_score'],
                    'is_active' => true,
                ]
            );
        }
    }
}
