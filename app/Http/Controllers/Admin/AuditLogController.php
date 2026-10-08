<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only view of the station's audit trail (admins only; there is no way to edit it). */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'entity' => ['nullable', 'string', 'max:60'],
            'action' => ['nullable', 'string', 'max:40'],
        ]);

        $logs = AuditLog::query()
            ->when($filters['entity'] ?? null, fn ($q, string $entity) => $q->where('entity_type', $entity))
            ->when($filters['action'] ?? null, fn ($q, string $action) => $q->where('action', $action))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('admin/audit-log/index', [
            'logs' => $logs,
            'filters' => $filters,
            'entities' => AuditLog::query()->whereNotNull('entity_type')->distinct()->orderBy('entity_type')->pluck('entity_type'),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
