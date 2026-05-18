<?php

namespace Database\Seeders;

use App\Models\Finding;
use App\Models\Project;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Users ──────────────────────────────────────────────────────────
        $admin = User::firstOrCreate(['email' => 'admin@adobe.com'], [
            'name'     => 'Admin User',
            'password' => Hash::make('password'),
            'role'     => 'admin',
        ]);

        $lead = User::firstOrCreate(['email' => 'lead@adobe.com'], [
            'name'     => 'Project Lead',
            'password' => Hash::make('password'),
            'role'     => 'project_lead',
        ]);

        $dev = User::firstOrCreate(['email' => 'dev@adobe.com'], [
            'name'     => 'Developer',
            'password' => Hash::make('password'),
            'role'     => 'member',
        ]);

        // ── Projects ───────────────────────────────────────────────────────
        $projects = [
            [
                'name'        => 'Magento Commerce Core',
                'dr_no'       => 'DR47000018',
                'description' => 'Core Magento 2 commerce module',
                'repo_url'    => 'https://github.com/adobe-commerce/magento2',
                'tech_stack'  => ['php', 'magento2', 'mysql'],
                'status'      => 'active',
            ],
            [
                'name'        => 'Payment Gateway Extension',
                'dr_no'       => 'DR47000019',
                'description' => 'Payment gateway integration module',
                'repo_url'    => 'https://github.com/adobe-commerce/payment-gw',
                'tech_stack'  => ['php', 'magento2'],
                'status'      => 'active',
            ],
            [
                'name'        => 'Inventory Management Module',
                'dr_no'       => 'DR47000020',
                'description' => 'Multi-source inventory module',
                'repo_url'    => 'https://github.com/adobe-commerce/inventory',
                'tech_stack'  => ['php', 'magento2', 'mysql'],
                'status'      => 'in_review',
            ],
            [
                'name'        => 'B2B Commerce Suite',
                'dr_no'       => 'DR47000021',
                'description' => 'B2B features for Adobe Commerce',
                'repo_url'    => 'https://github.com/adobe-commerce/b2b',
                'tech_stack'  => ['php', 'magento2'],
                'status'      => 'active',
            ],
        ];

        foreach ($projects as $projectData) {
            $project = Project::firstOrCreate(
                ['dr_no' => $projectData['dr_no']],
                [...$projectData, 'created_by' => $admin->id]
            );
            $project->members()->syncWithoutDetaching([
                $admin->id => ['role' => 'lead'],
                $lead->id  => ['role' => 'lead'],
                $dev->id   => ['role' => 'member'],
            ]);
        }

        // ── Scans with varied scores ────────────────────────────────────────
        $scanConfigs = [
            ['score' => 87, 'grade' => 'A', 'critical' => 2,  'error' => 5,  'warning' => 12, 'info' => 30],
            ['score' => 62, 'grade' => 'C', 'critical' => 8,  'error' => 20, 'warning' => 35, 'info' => 50],
            ['score' => 41, 'grade' => 'D', 'critical' => 15, 'error' => 40, 'warning' => 60, 'info' => 80],
            ['score' => 91, 'grade' => 'A+','critical' => 0,  'error' => 3,  'warning' => 8,  'info' => 20],
        ];

        foreach (Project::all() as $index => $project) {
            $cfg = $scanConfigs[$index % count($scanConfigs)];

            Scan::firstOrCreate(
                ['project_id' => $project->id, 'status' => 'complete'],
                [
                    'triggered_by'       => $admin->id,
                    'status'             => 'complete',
                    'score'              => $cfg['score'],
                    'grade'              => $cfg['grade'],
                    'total_files'        => rand(50, 300),
                    'total_lines'        => rand(5000, 50000),
                    'critical_count'     => $cfg['critical'],
                    'error_count'        => $cfg['error'],
                    'warning_count'      => $cfg['warning'],
                    'info_count'         => $cfg['info'],
                    'category_breakdown' => [
                        'security'        => $cfg['critical'],
                        'performance'     => $cfg['error'],
                        'maintainability' => $cfg['warning'],
                        'standards'       => $cfg['info'],
                    ],
                    'sub_indexes' => [
                        'security'        => $cfg['score'] - 5,
                        'maintainability' => $cfg['score'] + 3,
                        'performance'     => $cfg['score'] - 2,
                    ],
                    'started_at'   => now()->subMinutes(30),
                    'completed_at' => now()->subMinutes(25),
                ]
            );
        }
    }
}
