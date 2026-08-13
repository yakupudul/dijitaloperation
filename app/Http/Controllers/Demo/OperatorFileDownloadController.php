<?php

namespace App\Http\Controllers\Demo;

use App\Models\OperatorFile;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OperatorFileDownloadController
{
    use AuthorizesRequests;

    public function __invoke(Request $request, OperatorFile $file): StreamedResponse
    {
        $this->authorize('download', $file);

        $disk = Storage::disk($file->disk);

        abort_unless($disk->exists($file->path), 404);

        return $disk->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime ?: 'application/octet-stream',
        ]);
    }
}
