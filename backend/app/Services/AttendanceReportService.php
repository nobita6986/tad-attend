<?php
namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shift-window attendance summary (-4h/+4h).
 * Supports both single shared kiosk devices (`both`: first punch = IN, last punch = OUT)
 * and dedicated directional gates (`in` / `out`), with strict branch scoping and
 * branch default-shift fallback.
 */
class AttendanceReportService
{
    /**
     * @param  array<int>|null  $branchIds  null = global (no branch restriction); [] = no access
     */
    public function dailyRows(string $from, string $to, ?int $employeeId = null, ?array $branchIds = null): array
    {
        $tz = config('attendance.timezone');
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->startOfDay();
        if ($end->lt($start) || $start->diffInDays($end) > 62) {
            throw new \InvalidArgumentException('Khoảng báo cáo phải từ 1 đến 63 ngày.');
        }

        if ($branchIds !== null && $branchIds === []) {
            return [];
        }

        $employees = Employee::query()
            ->with(['branch.defaultShift', 'shift', 'shiftAssignments.shift'])
            ->when($employeeId, fn ($q) => $q->whereKey($employeeId))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('code')
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $employeeIds = $employees->pluck('id')->all();
        $punches = AttendanceEvent::query()
            ->where('review_state', 'accepted')
            ->whereIn('direction', ['both', 'in', 'out'])
            ->whereIn('employee_id', $employeeIds)
            ->where('captured_at', '>=', $start->subHours(4)->utc())
            ->where('captured_at', '<=', $end->addDays(2)->utc())
            ->orderBy('captured_at')
            ->get()
            ->groupBy('employee_id');

        $rows = [];
        foreach ($employees as $employee) {
            $employeePunches = $punches->get($employee->id, collect());
            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $shift = $this->shiftOn($employee, $day->toDateString());
                $base = [
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->code,
                    'employee_name' => $employee->full_name,
                    'branch_id' => $employee->branch_id,
                    'branch_code' => $employee->branch?->code ?? '—',
                    'branch_name' => $employee->branch?->name ?? 'Chưa gán chi nhánh',
                    'day' => $day->toDateString(),
                    'shift' => $shift?->name ?? '-',
                    'in' => null,
                    'out' => null,
                    'work_minutes' => 0,
                    'late_minutes' => 0,
                    'early_minutes' => 0,
                    'punches' => 0,
                ];
                // Do not mark somebody absent before joining or after leaving.
                if (($employee->employed_from && $day->toDateString() < $employee->employed_from->toDateString())
                    || ($employee->employed_until && $day->toDateString() > $employee->employed_until->toDateString())) {
                    $rows[] = $base + ['status' => 'not_employed'];
                    continue;
                }
                if (!$shift) {
                    $rows[] = $base + ['status' => 'unconfigured'];
                    continue;
                }
                $days = $shift->weekdays;
                if ($days !== null && !in_array($day->dayOfWeekIso, $days, false)) {
                    $rows[] = $base + ['status' => 'off'];
                    continue;
                }
                $scheduledIn = $day->setTimeFromTimeString($shift->starts_at);
                $scheduledOut = $day->setTimeFromTimeString($shift->ends_at);
                if ($scheduledOut->lte($scheduledIn)) {
                    $scheduledOut = $scheduledOut->addDay();
                }
                $windowIn = $scheduledIn->subHours(4)->utc();
                $windowOut = $scheduledOut->addHours(4)->utc();
                $events = $employeePunches->filter(fn ($ev) => $ev->captured_at->betweenIncluded($windowIn, $windowOut));
                $firstIn = $events->first(fn ($ev) => in_array($ev->direction, ['in', 'both'], true));
                $lastOut = $events->reverse()->first(fn ($ev) => in_array($ev->direction, ['out', 'both'], true) && (!$firstIn || $ev->captured_at->gt($firstIn->captured_at)));
                $base['in'] = $firstIn?->captured_at;
                $base['out'] = $lastOut?->captured_at;
                $base['punches'] = $events->count();
                if (!$firstIn && !$lastOut) {
                    $rows[] = $base + ['status' => 'absent'];
                    continue;
                }
                if (!$firstIn || !$lastOut) {
                    $rows[] = $base + ['status' => 'incomplete'];
                    continue;
                }
                $base['work_minutes'] = max(0, (int) $firstIn->captured_at->diffInMinutes($lastOut->captured_at) - (int) $shift->break_minutes);
                $base['late_minutes'] = max(0, (int) $scheduledIn->addMinutes((int) $shift->grace_minutes)->diffInMinutes($firstIn->captured_at->setTimezone($tz), false));
                $base['early_minutes'] = max(0, (int) $lastOut->captured_at->setTimezone($tz)->diffInMinutes($scheduledOut, false));
                $rows[] = $base + ['status' => 'complete'];
            }
        }

        return $rows;
    }

    public function summary(array $rows): array
    {
        return collect($rows)->groupBy('employee_id')->map(function (Collection $days) {
            $first = $days->first();

            return [
                'employee_id' => $first['employee_id'],
                'employee_code' => $first['employee_code'],
                'employee_name' => $first['employee_name'],
                'branch_code' => $first['branch_code'] ?? '—',
                'branch_name' => $first['branch_name'] ?? 'Chưa gán chi nhánh',
                'work_days' => $days->where('status', 'complete')->count(),
                'absent_days' => $days->where('status', 'absent')->count(),
                'incomplete_days' => $days->where('status', 'incomplete')->count(),
                'late_days' => $days->filter(fn ($r) => $r['late_minutes'] > 0)->count(),
                'early_days' => $days->filter(fn ($r) => $r['early_minutes'] > 0)->count(),
                'work_minutes' => $days->sum('work_minutes'),
                'punches' => $days->sum('punches'),
            ];
        })->values()->all();
    }

    private function shiftOn(Employee $employee, string $day): ?Shift
    {
        $assignment = $employee->shiftAssignments->sortByDesc('effective_from')->first(fn ($a) =>
            $a->effective_from->toDateString() <= $day && (!$a->effective_to || $a->effective_to->toDateString() >= $day)
        );

        return $assignment?->shift ?: $employee->shift ?: $employee->branch?->defaultShift;
    }
}
