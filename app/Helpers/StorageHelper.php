<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class StorageHelper
{
    /**
     * Get the appropriate disk based on environment
     */
    public static function getStorageDisk()
    {
        if (self::isRailwayWithVolume()) {
            Log::info('Using railway_volume disk', [
                'path' => env('RAILWAY_VOLUME_PATH'),
                'exists' => file_exists(env('RAILWAY_VOLUME_PATH', '/data/storage'))
            ]);
            return Storage::disk('railway_volume');
        }
        
        Log::info('Using public disk for local development');
        return Storage::disk('public');
    }
    
    /**
     * Check if we're running on Railway with a volume mounted
     */
    public static function isRailwayWithVolume()
    {
        $volumePath = env('RAILWAY_VOLUME_PATH');
        
        $isRailway = env('RAILWAY_ENVIRONMENT') === 'production' 
            && $volumePath 
            && file_exists($volumePath);
        
        if (!$isRailway && strpos(env('APP_URL', ''), 'railway.app') !== false) {
            return true;
        }
        
        return $isRailway;
    }
    
    /**
     * Store a file using the appropriate disk
     */
    public static function storeFile($file, $path, $customFilename = null)
    {
        $disk = self::getStorageDisk();
        
        if ($customFilename) {
            $filePath = $path . '/' . $customFilename;
            $disk->put($filePath, file_get_contents($file));
            return $filePath;
        } else {
            return $disk->putFile($path, $file);
        }
    }
    
    /**
     * Get public URL for stored file
     */
    public static function getPublicUrl($path)
    {
        if (empty($path)) {
            return null;
        }
        
        // FORCE FIX: Extract relative path from any URL
        if (is_string($path) && (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0)) {
            // Try to extract the relative path from the URL
            // Look for patterns like /storage/... or /api/storage/...
            if (preg_match('/(?:storage|api\/storage)\/(.+)$/', $path, $matches)) {
                $relativePath = $matches[1];
                Log::info('Extracted relative path from URL', ['original' => $path, 'relative' => $relativePath]);
                $path = $relativePath;
            } else {
                // Not a storage URL, return as-is
                return $path;
            }
        }
        
        // Now path should be relative like: students/avatar/avatar_xxx.png
        $cleanPath = ltrim($path, '/');
        
        // Generate correct URL based on environment
        if (self::isRailwayWithVolume()) {
            $baseUrl = rtrim(env('APP_URL', 'https://be-ktx-production.up.railway.app'), '/');
            $finalUrl = $baseUrl . '/api/storage/' . $cleanPath;
            Log::info('Generated Railway URL', ['path' => $cleanPath, 'url' => $finalUrl]);
            return $finalUrl;
        }
        
        // Local development
        $baseUrl = rtrim(env('APP_URL', 'http://localhost:8000'), '/');
        return $baseUrl . '/storage/' . $cleanPath;
    }
    
    /**
     * Get the full filesystem path for a stored file
     */
    public static function getFullPath($path)
    {
        if (empty($path)) {
            return null;
        }
        
        $disk = self::getStorageDisk();
        return $disk->path($path);
    }
}