<?php

namespace Tests\Fixtures;

final class WeeklyPlanningV3E2eFixture
{
    public const ORGANIZATION = [
        'name' => 'KPS Weekly Planning V3 E2E (Fake)',
        // Runtime membership resolution uses the fixed KPS organization code.
        'code' => 'KPS',
    ];

    public const PASSWORD = 'Kps-V3-E2E-Only!2026';

    public const ACCOUNTS = [
        [
            'name' => 'V3 E2E HR (Fake)',
            'email' => 'hr@weekly-plan-v3-e2e.example.test',
            'primary_role' => 'HR_MANAGER',
            'secondary_roles' => [],
            'department' => 'HR_ADMIN',
        ],
        [
            'name' => 'V3 E2E Assistant (Fake)',
            'email' => 'assistant@weekly-plan-v3-e2e.example.test',
            'primary_role' => 'EXECUTIVE_ASSISTANT',
            'secondary_roles' => [],
            'department' => 'HR_ADMIN',
        ],
        [
            'name' => 'V3 E2E Owner (Fake)',
            'email' => 'owner@weekly-plan-v3-e2e.example.test',
            'primary_role' => 'OWNER',
            'secondary_roles' => [],
            'department' => null,
        ],
        [
            'name' => 'V3 E2E Multi Reviewer (Fake)',
            'email' => 'multi-reviewer@weekly-plan-v3-e2e.example.test',
            'primary_role' => 'OWNER',
            'secondary_roles' => ['EXECUTIVE_ASSISTANT'],
            'department' => 'HR_ADMIN',
        ],
    ];

    public const EMPLOYEES = [
        ['name' => 'V3 E2E Employee A (Fake)', 'email' => 'employee-a@weekly-plan-v3-e2e.example.test', 'role' => 'OFFICE_STAFF', 'department' => 'MARKETING'],
        ['name' => 'V3 E2E Employee B (Fake)', 'email' => 'employee-b@weekly-plan-v3-e2e.example.test', 'role' => 'OFFICE_STAFF', 'department' => 'MARKETING'],
        ['name' => 'V3 E2E Employee C (Fake)', 'email' => 'employee-c@weekly-plan-v3-e2e.example.test', 'role' => 'OFFICE_STAFF', 'department' => 'MARKETING'],
        ['name' => 'V3 E2E Zero Work Employee (Fake)', 'email' => 'zero-work@weekly-plan-v3-e2e.example.test', 'role' => 'OFFICE_STAFF', 'department' => 'MARKETING'],
    ];

    private function __construct() {}
}
