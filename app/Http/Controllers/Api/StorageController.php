<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Helpers\StorageHelper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class StorageController extends Controller
{
    /**
     * Serve images from Railway volume
     * Route: /api/storage/{path}
     * Where {path} is something like: students/avatar/filename.jpg
     */
    public function serveImage($path)
    {
        try {
            // Security: Prevent directory traversal attacks
            $path = ltrim($path, '/');
            
            // Reject any paths trying to traverse directories
            if (strpos($path, '..') !== false) {
                Log::warning('Path traversal attempt', ['path' => $path]);
                return response()->json(['error' => 'Invalid file path'], 400);
            }
            
            // Extract filename for MIME detection
            $filename = basename($path);
            
            // Check if using Railway volume
            if (StorageHelper::isRailwayWithVolume()) {
                $disk = Storage::disk('railway_volume');
                
                if (!$disk->exists($path)) {
                    Log::error('File not found', ['path' => $path]);
                    return response()->json(['error' => 'File not found'], 404);
                }
                
                // Get file contents
                $fileContent = $disk->get($path);
                
                // Your excellent MIME type detection
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($fileContent) ?: null;

                if (!$mimeType) {
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $mimeType = match ($extension) {
                        'jpg', 'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'gif' => 'image/gif',
                        'webp' => 'image/webp',
                        'svg' => 'image/svg+xml',
                        'bmp' => 'image/bmp',
                        'ico' => 'image/x-icon',
                        default => 'application/octet-stream',
                    };
                }
                
                // Cache for 1 hour
                return response($fileContent)
                    ->header('Content-Type', $mimeType)
                    ->header('Cache-Control', 'public, max-age=3600')
                    ->header('Access-Control-Allow-Origin', '*'); // Add CORS if needed
            }
            
            // Fallback for local development — try public disk first, then local disk
            $publicDisk = Storage::disk('public');
            $localDisk  = Storage::disk('local');

            if ($publicDisk->exists($path)) {
                $disk = $publicDisk;
            } elseif ($localDisk->exists($path)) {
                $disk = $localDisk;
            } else {
                Log::error('File not found (local)', ['path' => $path]);
                return response()->json(['error' => 'File not found'], 404);
            }

            $fileContent = $disk->get($path);
            $filename    = basename($path);
            $finfo       = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType    = $finfo->buffer($fileContent) ?: null;

            if (!$mimeType) {
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $mimeType  = match ($extension) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png'         => 'image/png',
                    'gif'         => 'image/gif',
                    'webp'        => 'image/webp',
                    'svg'         => 'image/svg+xml',
                    'pdf'         => 'application/pdf',
                    default       => 'application/octet-stream',
                };
            }

            return response($fileContent)
                ->header('Content-Type', $mimeType)
                ->header('Cache-Control', 'public, max-age=3600')
                ->header('Access-Control-Allow-Origin', '*');
            
        } catch (\Exception $e) {
            Log::error('Error serving image', [
                'error' => $e->getMessage(),
                'path' => $path ?? 'unknown'
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
    
    /**
     * Alternative: Serve by directory and filename (if you prefer two params)
     * Route: /api/storage/{directory}/{filename}
     * Only works for single-level directories
     */
    public function serveImageByParts($directory, $filename)
    {
        // Combine them and pass to serveImage
        $path = $directory . '/' . $filename;
        return $this->serveImage($path);
    }
}