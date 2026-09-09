<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Services\SearchDemand\ManualQueryClusterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ManualClusterDownloadController extends Controller
{
    public function __invoke(Request $request, int $operation): BinaryFileResponse
    {
        app(ManualQueryClusterService::class)->authorize($request->user());
        $op = DB::table('library_cluster_operations')->find($operation);
        abort_unless($op && $op->kind === 'export' && $op->status === 'completed'
            && $op->file_path === 'library-cluster-exports/'.$operation.'/queries.csv', 404);
        app(ManualQueryClusterService::class)->service($op->service_id);
        abort_unless(Storage::disk('local')->exists($op->file_path), 404);

        return response()->download(Storage::disk('local')->path($op->file_path), 'query-clusters-'.$operation.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

