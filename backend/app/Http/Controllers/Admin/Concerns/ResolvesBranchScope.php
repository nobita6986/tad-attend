<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait ResolvesBranchScope
{
    /**
     * Resolves the effective branch ID filter for the authenticated user and request query params.
     *
     * @return array{
     *   accessibleBranches: Collection<int, Branch>,
     *   selectedRegion: ?string,
     *   selectedBranchId: ?int,
     *   effectiveBranchIds: ?array<int>
     * }
     */
    protected function resolveBranchFilter(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $allowedIds = $user->accessibleBranchIds(); // null = global
        $accessibleBranches = $user->accessibleBranchesQuery()->get();

        $region = $request->query('region');
        if (!is_string($region) || !array_key_exists($region, Branch::REGIONS)) {
            $region = null;
        }

        $branchIdParam = $request->query('branch_id');
        $selectedBranchId = (is_numeric($branchIdParam) && (int) $branchIdParam > 0)
            ? (int) $branchIdParam
            : null;

        if ($selectedBranchId !== null) {
            abort_unless($user->canAccessBranch($selectedBranchId), 403, 'Bạn không có quyền truy cập dữ liệu chi nhánh này.');
        }

        // Compute effective branch IDs
        if ($selectedBranchId !== null) {
            $effectiveBranchIds = [$selectedBranchId];
        } elseif ($region !== null) {
            $regionBranchIds = $accessibleBranches
                ->where('region', $region)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $effectiveBranchIds = $regionBranchIds;
        } else {
            $effectiveBranchIds = $allowedIds; // null for global admin/hr, or explicit IDs for regional/branch accountant
        }

        return [
            'accessibleBranches' => $accessibleBranches,
            'selectedRegion' => $region,
            'selectedBranchId' => $selectedBranchId,
            'effectiveBranchIds' => $effectiveBranchIds,
        ];
    }

    protected function applyAttendanceBranchScope($query, ?array $branchIds)
    {
        if ($branchIds === null) {
            return $query;
        }
        if ($branchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($branchIds) {
            $q->whereIn('attendance_events.branch_id', $branchIds)
                ->orWhere(function ($q2) use ($branchIds) {
                    $q2->whereNull('attendance_events.branch_id')
                        ->whereHas('employee', fn ($qe) => $qe->whereIn('branch_id', $branchIds));
                });
        });
    }
}
