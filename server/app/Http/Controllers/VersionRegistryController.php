<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class VersionRegistryController extends Controller
{
    public function index(Request $request)
    {
        $registryPath = base_path('../config/versions/registry.yaml');

        if (! file_exists($registryPath)) {
            return response()->json(['versions' => []]);
        }

        $versions = $this->parseKnownVersions(file_get_contents($registryPath));

        return response()->json(['versions' => $versions]);
    }

    public function sync(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        try {
            $exitCode = Artisan::call('upgrade:version-sync');
            $output   = Artisan::output();

            if ($exitCode !== 0) {
                return response()->json([
                    'synced'  => false,
                    'message' => trim($output) ?: 'Sync command failed.',
                ], 500);
            }

            return response()->json([
                'synced'  => true,
                'message' => 'Sync completed',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'synced'  => false,
                'message' => 'Sync command not available: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function parseKnownVersions(string $yaml): array
    {
        $versions = [];
        if (preg_match('/known_versions:\s*((?:\s+-\s+"[^"]+"\s*)+)/m', $yaml, $block)) {
            preg_match_all('/-\s+"([^"]+)"/', $block[1], $matches);
            $versions = $matches[1] ?? [];
        }
        return $versions;
    }
}
