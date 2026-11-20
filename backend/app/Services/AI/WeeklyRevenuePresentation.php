<?php

namespace App\Services\AI;

/** Attach the employee's weekly figure alongside the unmodified saved report. */
final class WeeklyRevenuePresentation
{
    public static function employee(array $employee, string $endDate): array
    {
        $revenue = WeeklyRevenue::employee($employee, $endDate);
        if ($revenue !== null) {
            $employee['weekly_revenue'] = $revenue;
        }

        return $employee;
    }
}
