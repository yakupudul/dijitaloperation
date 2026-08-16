<?php

namespace App\Services\Assistant;

use App\Support\Assistant\AssistantCapabilityRegistry;
use Illuminate\Support\Facades\File;

/**
 * Hard architectural boundaries for Prompt 56 Assistant.
 */
final class AssistantBoundaryGuard
{
    public function __construct(
        private readonly AssistantCapabilityRegistry $capabilities,
    ) {}

    public function assertSafeArchitecture(): void
    {
        if (class_exists('App\\Services\\Assistant\\AssistantV2')
            || class_exists('App\\Services\\Assistant\\ChatV2')
            || class_exists('App\\Services\\Assistant\\MoxdopBrainChatService')) {
            throw new \RuntimeException('Forbidden Assistant duplicate architecture detected.');
        }

        foreach ($this->capabilities->forbiddenCapabilityIds() as $id) {
            if ($this->capabilities->has($id)) {
                throw new \RuntimeException('Forbidden capability registered: '.$id);
            }
        }

        $dir = app_path('Services/Assistant');
        if (! is_dir($dir)) {
            return;
        }

        foreach (File::allFiles($dir) as $file) {
            if ($file->getFilename() === 'AssistantBoundaryGuard.php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            $stripped = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
            $stripped = preg_replace('#//.*$#m', '', $stripped) ?? $stripped;

            if (preg_match('/information_schema|SHOW\s+TABLES|DESCRIBE\s+|createEmbedding\s*\(|->fineTunes?\s*\(|pgvector_/i', $stripped)) {
                throw new \RuntimeException('Forbidden schema/training API in Assistant services: '.$file->getFilename());
            }

            if (preg_match('/function\s+(queryDatabase|executeSql|runSql|searchEverything|searchAllCustomers|searchAllMemory)\s*\(/i', $stripped)) {
                throw new \RuntimeException('Forbidden raw DB/search tool in Assistant services: '.$file->getFilename());
            }
        }
    }
}
