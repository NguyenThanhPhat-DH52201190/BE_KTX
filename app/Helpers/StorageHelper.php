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
        $isRailway = env('RAILWAY_ENVIRONMENT') === 'production' 
            && $volumePath 
            && file_exists($volumePath);
        
        // Also check if APP_URL contains railway.app (fallback)
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
        
        // FIX: If it's a full URL that's missing /api/ (like avatar), fix it
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            // If it's a Railway storage URL missing /api/, convert it
            if (strpos($path, 'railway.app') !== false && 
                strpos($path, '/storage/') !== false && 
                strpos($path, '/api/') === false) {
                
                $fixed = str_replace('/storage/', '/api/storage/', $path);
                Log::info('Fixed avatar URL missing /api/', ['original' => $path, 'fixed' => $fixed]);
                return $fixed;
            }
            return $path;
        }
        
        // If using Railway volume, return API endpoint URL
        if (self::isRailwayWithVolume()) {
            $url = url('/api/storage/' . ltrim($path, '/'));
            Log::info('Generated Railway URL', ['path' => $path, 'url' => $url]);
            return $url;
        }
        
        // Local development - use storage URL
        $url = Storage::url($path);
        Log::info('Generated local URL', ['path' => $path, 'url' => $url]);
        return $url;
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