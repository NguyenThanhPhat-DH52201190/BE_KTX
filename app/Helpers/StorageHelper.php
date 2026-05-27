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
        
        // 🔧 FIX: If path contains /storage/ without /api/, extract the relative path
        if (strpos($path, '/storage/') !== false && strpos($path, '/api/') === false) {
            $parts = explode('/storage/', $path, 2);
            $path = $parts[1] ?? $path;
            Log::info('Extracted relative path', ['new_path' => $path]);
        }
        
        // If it's already a full URL (but not after extraction)
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            Log::info('Returning existing URL', ['url' => $path]);
            return $path;
        }
        
        // Clean the path
        $cleanPath = ltrim($path, '/');
        
        // If using Railway volume, return API endpoint URL
        if (self::isRailwayWithVolume()) {
            // FORCE HTTPS and correct domain - DON'T use url() helper
            $baseUrl = 'https://be-ktx-production.up.railway.app';
            $finalUrl = $baseUrl . '/api/storage/' . $cleanPath;
            Log::info('Generated Railway URL', ['url' => $finalUrl]);
            return $finalUrl;
        }
        
        // Local development - use storage URL
        return Storage::url($cleanPath);
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