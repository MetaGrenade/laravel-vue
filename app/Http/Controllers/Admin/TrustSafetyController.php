<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDataErasureRequest;
use App\Http\Requests\Admin\UpdateDataExportRequest;
use App\Models\DataErasureRequest;
use App\Models\DataExport;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrustSafetyController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('trust_safety.acp.view'), 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'export_status' => ['nullable', 'string', Rule::in(array_merge(['all'], DataExport::STATUSES))],
            'erasure_status' => ['nullable', 'string', Rule::in(array_merge(['all'], DataErasureRequest::STATUSES))],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $search = Arr::get($validated, 'search');
        $search = is_string($search) ? trim($search) : null;
        $search = $search === '' ? null : $search;
        $exportStatus = Arr::get($validated, 'export_status', DataExport::STATUS_PENDING);
        $erasureStatus = Arr::get($validated, 'erasure_status', DataErasureRequest::STATUS_PENDING);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $perPage = max(5, min(100, $perPage));

        $exportPage = max(1, (int) $request->query('export_page', 1));
        $erasurePage = max(1, (int) $request->query('erasure_page', 1));

        $formatter = DateFormatter::for($request->user());

        $exportQuery = DataExport::query()
            ->with(['user:id,nickname,email'])
            ->orderByDesc('created_at');

        if ($exportStatus && $exportStatus !== 'all') {
            $exportStatuses = [$exportStatus];

            if ($exportStatus === DataExport::STATUS_PENDING) {
                $exportStatuses[] = DataExport::STATUS_PROCESSING;
            }

            $exportQuery->whereIn('status', array_unique($exportStatuses));
        }

        if ($search !== null) {
            $exportQuery->where(function ($query) use ($search) {
                $query->whereHas('user', function ($query) use ($search) {
                    $query
                        ->where('email', 'like', "%{$search}%")
                        ->orWhere('nickname', 'like', "%{$search}%");
                })
                ->orWhere('id', $search);
            });
        }

        $exports = $exportQuery
            ->paginate($perPage, ['*'], 'export_page', $exportPage)
            ->withQueryString()
            ->through(function (DataExport $export) use ($formatter) {
                return [
                    'id' => $export->id,
                    'status' => $export->status,
                    'format' => $export->format,
                    'file_path' => $export->file_path,
                    'failure_reason' => $export->failure_reason,
                    'created_at' => $formatter->iso($export->created_at),
                    'completed_at' => $formatter->iso($export->completed_at),
                    'updated_at' => $formatter->iso($export->updated_at),
                    'user' => $export->user ? [
                        'id' => $export->user->id,
                        'nickname' => $export->user->nickname,
                        'email' => $export->user->email,
                    ] : null,
                ];
            });

        $erasureQuery = DataErasureRequest::query()
            ->with(['user:id,nickname,email'])
            ->orderByDesc('created_at');

        if ($erasureStatus && $erasureStatus !== 'all') {
            $erasureStatuses = [$erasureStatus];

            if ($erasureStatus === DataErasureRequest::STATUS_PENDING) {
                $erasureStatuses[] = DataErasureRequest::STATUS_PROCESSING;
            }

            $erasureQuery->whereIn('status', array_unique($erasureStatuses));
        }

        if ($search !== null) {
            $erasureQuery->where(function ($query) use ($search) {
                $query->whereHas('user', function ($query) use ($search) {
                    $query
                        ->where('email', 'like', "%{$search}%")
                        ->orWhere('nickname', 'like', "%{$search}%");
                })
                ->orWhere('id', $search);
            });
        }

        $erasureRequests = $erasureQuery
            ->paginate($perPage, ['*'], 'erasure_page', $erasurePage)
            ->withQueryString()
            ->through(function (DataErasureRequest $erasureRequest) use ($formatter) {
                return [
                    'id' => $erasureRequest->id,
                    'status' => $erasureRequest->status,
                    'created_at' => $formatter->iso($erasureRequest->created_at),
                    'processed_at' => $formatter->iso($erasureRequest->processed_at),
                    'updated_at' => $formatter->iso($erasureRequest->updated_at),
                    'user' => $erasureRequest->user ? [
                        'id' => $erasureRequest->user->id,
                        'nickname' => $erasureRequest->user->nickname,
                        'email' => $erasureRequest->user->email,
                    ] : null,
                ];
            });

        $exportCounts = DataExport::query()
            ->select(['status', DB::raw('COUNT(*) as aggregate')])
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $erasureCounts = DataErasureRequest::query()
            ->select(['status', DB::raw('COUNT(*) as aggregate')])
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return Inertia::render('acp/TrustSafety', [
            'exports' => $exports,
            'erasureRequests' => $erasureRequests,
            'filters' => [
                'search' => $search,
                'export_status' => $exportStatus,
                'erasure_status' => $erasureStatus,
                'per_page' => $perPage,
            ],
            'counts' => [
                'exports' => $exportCounts,
                'erasure' => $erasureCounts,
            ],
            'statusOptions' => [
                'exports' => DataExport::STATUSES,
                'erasure' => DataErasureRequest::STATUSES,
            ],
        ]);
    }

    public function updateExport(UpdateDataExportRequest $request, DataExport $export): RedirectResponse
    {
        $data = $request->validated();
        $status = $data['status'];

        $export->forceFill([
            'status' => $status,
            'file_path' => $data['file_path'] ?? null,
            'failure_reason' => $status === DataExport::STATUS_FAILED ? ($data['failure_reason'] ?? null) : null,
            'completed_at' => $status === DataExport::STATUS_COMPLETED
                ? ($data['completed_at'] ? Carbon::parse($data['completed_at']) : now())
                : null,
        ])->save();

        if ($status !== DataExport::STATUS_COMPLETED) {
            $export->purgeExpiredFile();
        }

        return back()->with('success', 'Data export updated.');
    }

    public function updateErasure(UpdateDataErasureRequest $request, DataErasureRequest $erasureRequest): RedirectResponse
    {
        $data = $request->validated();
        $status = $data['status'];

        $erasureRequest->forceFill([
            'status' => $status,
            'processed_at' => in_array($status, [
                DataErasureRequest::STATUS_COMPLETED,
                DataErasureRequest::STATUS_REJECTED,
            ], true)
                ? ($data['processed_at'] ? Carbon::parse($data['processed_at']) : now())
                : null,
        ])->save();

        return back()->with('success', 'Data erasure request updated.');
    }
}
