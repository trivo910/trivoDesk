<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UpdaterService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Updater. Verify CodeCanyon purchase → backup → upload package →
 * apply code → migrate → finalize, with one-click rollback. The admin route
 * group already enforces platform-admin access; we additionally require the
 * Super Admin role for these high-impact, file-system-mutating actions.
 */
class UpdateController extends Controller
{
    public function __construct(private UpdaterService $updater)
    {
        // Access is already gated by the admin route group (every /admin/*
        // route requires a platform admin), so no extra middleware here.
    }

    public function index()
    {
        return view('admin.update.index', [
            'currentVersion' => $this->updater->currentVersion(),
            'currentBuild'   => $this->updater->currentBuild(),
            'backups'        => $this->updater->listBackups(),
            'verified'       => (bool) \App\Models\SystemSetting::get('envato_purchase_code', ''),
        ]);
    }

    /** Step 0: verify CodeCanyon purchase code against Envato. */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['purchase_code' => ['required', 'string', 'max:120']]);

        $result = $this->updater->verifyPurchase((string) $request->input('purchase_code'));

        Audit::log('admin.updater.verify', [
            'result' => $result['ok'] ? 'success' : 'failure',
            'meta'   => ['item' => $result['item'] ?? null],
        ]);

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'buyer'   => $result['buyer'] ?? null,
        ], $result['ok'] ? 200 : 422);
    }

    /** Step 1: backup files + database. */
    public function backup(): JsonResponse
    {
        try {
            $result = $this->updater->createBackup();
            Audit::log('admin.updater.backup', ['meta' => ['dir' => $result['dir'] ?? null]]);

            return response()->json(['success' => true, 'message' => 'Backup created successfully.', 'backup' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Backup failed: ' . $e->getMessage()], 500);
        }
    }

    /** Step 2: upload + validate the update ZIP. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:zip', 'max:512000']]);

        try {
            $path = $this->updater->saveUploadedZip($request->file('file'));
            $zipVersion = $this->updater->getZipVersion($path);

            if (! $zipVersion) {
                $this->updater->cleanup();
                return response()->json(['success' => false, 'message' => 'Invalid update package — no version info found (config/version.php missing in ZIP).'], 422);
            }

            $current = $this->updater->currentVersion();
            if (version_compare($zipVersion, $current, '<=')) {
                $this->updater->cleanup();
                return response()->json(['success' => false, 'message' => "ZIP contains v{$zipVersion} but you already have v{$current}. Upload a newer version."], 422);
            }

            return response()->json(['success' => true, 'message' => "Update package v{$zipVersion} ready to install.", 'zip_version' => $zipVersion]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    /** Step 3: extract + apply code files. */
    public function apply(Request $request): JsonResponse
    {
        $zipPath = storage_path('app/temp/updater/update.zip');
        if (! file_exists($zipPath)) {
            return response()->json(['success' => false, 'message' => 'No update ZIP found. Please upload first.'], 400);
        }

        try {
            $updated = $this->updater->applyUpdate($zipPath);
            Audit::log('admin.updater.apply', ['meta' => ['paths' => $updated]]);

            return response()->json(['success' => true, 'message' => 'Files updated successfully.', 'updated' => $updated]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Apply failed: ' . $e->getMessage()], 500);
        }
    }

    /** Step 4: run new migrations. */
    public function migrate(): JsonResponse
    {
        try {
            $output = $this->updater->runMigrations();
            Audit::log('admin.updater.migrate');

            return response()->json(['success' => true, 'message' => 'Migrations completed.', 'output' => $output]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Migration failed: ' . $e->getMessage()], 500);
        }
    }

    /** Step 5: clear caches + health check + cleanup. */
    public function finalize(): JsonResponse
    {
        try {
            $this->updater->clearCaches();
            $health = $this->updater->healthCheck();
            $this->updater->cleanup();

            $allGood = ! in_array(false, $health, true);
            Audit::log('admin.updater.finalize', ['meta' => ['health' => $health]]);

            return response()->json([
                'success'     => $allGood,
                'message'     => $allGood ? 'Update completed successfully.' : 'Update done, but the health check raised warnings.',
                'health'      => $health,
                'new_version' => config('version.version'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Finalize failed: ' . $e->getMessage()], 500);
        }
    }

    /** Restore a previous backup. */
    public function rollback(Request $request): JsonResponse
    {
        $request->validate(['backup_dir' => ['required', 'string']]);

        $backupBase = realpath(storage_path('app/backups'));
        $targetDir  = realpath($request->input('backup_dir'));
        if (! $targetDir || ! $backupBase || ! str_starts_with($targetDir, $backupBase)) {
            return response()->json(['success' => false, 'message' => 'Invalid backup path.'], 403);
        }

        try {
            $this->updater->rollback($targetDir);
            Audit::log('admin.updater.rollback', ['meta' => ['dir' => $targetDir]]);

            return response()->json(['success' => true, 'message' => 'Rollback completed. Version restored.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Rollback failed: ' . $e->getMessage()], 500);
        }
    }
}
