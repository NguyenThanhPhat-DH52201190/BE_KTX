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
        
        // If it's a full URL, extract just the relative path
        if (strpos($path, '/storage/') !== false) {
            $path = explode('/storage/', $path, 2)[1] ?? $path;
        }
        
        // Clean the path
        $cleanPath = ltrim($path, '/');
        
        // If using Railway volume, return ABSOLUTE API endpoint URL
        if (self::isRailwayWithVolume()) {
            // Get the absolute URL - THIS IS THE KEY FIX
            $baseUrl = rtrim(env('APP_URL', 'https://be-ktx-production.up.railway.app'), '/');
            $absoluteUrl = $baseUrl . '/api/storage/' . $cleanPath;
            
            Log::info('Generated absolute Railway URL', [
                'clean_path' => $cleanPath,
                'url' => $absoluteUrl
            ]);
            
            return $absoluteUrl;
        }
        
        // Local development - use storage URL
        $localUrl = Storage::url($cleanPath);
        return $localUrl;
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