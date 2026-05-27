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
        // Check if running on Railway with volume
        if (self::isRailwayWithVolume()) {
            Log::info('Using railway_volume disk', [
                'path' => env('RAILWAY_VOLUME_PATH'),
                'exists' => file_exists(env('RAILWAY_VOLUME_PATH', '/data/storage'))
            ]);
            return Storage::disk('railway_volume');
        }
        
        // Local development - use public disk
        Log::info('Using public disk for local development');
        return Storage::disk('public');
    }
    
    /**
     * Check if we're running on Railway with a volume mounted
     */
    public static function isRailwayWithVolume()
    {
        $volumePath = env('RAILWAY_VOLUME_PATH');
        
        // Check if Railway environment and volume path exists
        return env('RAILWAY_ENVIRONMENT') === 'production' 
            && $volumePath 
            && file_exists($volumePath);
    }
    
    /**
     * Store a file using the appropriate disk
     */
    public static function storeFile($file, $path, $customFilename = null)
    {
        $disk = self::getStorageDisk();
        
        if ($customFilename) {
            // Store with custom filename
            $filePath = $path . '/' . $customFilename;
            $disk->put($filePath, file_get_contents($file));
            return $filePath;
        } else {
            // Store with generated filename
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
        
        // FIX: Convert full URLs to relative paths before processing
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            // If it's a storage URL, extract the relative path
            if (strpos($path, '/storage/') !== false) {
                // Extract everything after '/storage/'
                $parts = explode('/storage/', $path, 2);
                if (isset($parts[1])) {
                    $path = $parts[1];
                    Log::info('Converted URL to relative path', ['original' => $originalPath ?? $path, 'relative' => $path]);
                } else {
                    return $path;
                }
            } else {
                // Not a storage URL, return as-is
                return $path;
            }
        }
        
        // If using Railway volume, return API endpoint URL
        if (self::isRailwayWithVolume()) {
            // Keep the full path intact for nested directories
            return url('/api/storage/' . ltrim($path, '/'));
        }
        
        // Local development - use storage URL
        return Storage::url($path);
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