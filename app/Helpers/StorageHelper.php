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
        
        // SIMPLE FIX: If it's a URL, extract everything after '/storage/'
        if (strpos($path, 'https://') === 0 || strpos($path, 'http://') === 0) {
            // Find the position of '/storage/'
            $storagePos = strpos($path, '/storage/');
            if ($storagePos !== false) {
                // Get everything after '/storage/'
                $path = substr($path, $storagePos + 9); // +9 for '/storage/'
                Log::info('Extracted path from URL', ['original' => $originalPath ?? $path, 'extracted' => $path]);
            }
        }
        
        // Remove any leading slashes
        $cleanPath = ltrim($path, '/');
        
        // For Railway: Always use the correct base URL with /api/storage/
        if (self::isRailwayWithVolume()) {
            // Use the correct Railway app URL (be-ktx-production, not bektx-production)
            $baseUrl = 'https://be-ktx-production.up.railway.app';
            return $baseUrl . '/api/storage/' . $cleanPath;
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