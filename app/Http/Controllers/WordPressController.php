<?php

namespace App\Http\Controllers;

use App\WordPress\Connector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class WordPressController extends Controller
{
    /** Called once by the plugin with the code pasted into its settings page; answers with the shared secret. */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'site_url' => ['required', 'string', 'max:2048'],
            'plugin_version' => ['nullable', 'string', 'max:20'],
        ]);

        $result = Connector::pair($data['code'], $data['site_url'], $data['plugin_version'] ?? null);

        return isset($result['secret'])
            ? response()->json(['secret' => $result['secret']])
            : response()->json(['message' => $result['error']], 422);
    }

    /** The Connector plugin as a ZIP for WordPress's "Upload Plugin" screen, built from the copy in this repository. */
    public function plugin(): BinaryFileResponse
    {
        abort_unless(auth()->user()?->canWrite() ?? false, 403);

        $dir = base_path('wordpress-plugin/screamingrabbit-connector');
        $zipPath = tempnam(sys_get_temp_dir(), 'sr-plugin');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $zip->addFile($file->getPathname(), 'screamingrabbit-connector/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/'));
        }
        $zip->close();

        return response()->download($zipPath, 'screamingrabbit-connector.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
