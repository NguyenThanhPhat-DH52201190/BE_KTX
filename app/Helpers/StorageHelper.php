<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class StorageHelper
{
    public static function getStorageDisk()
    {
        if (self::isRailwayWithVolume()) {
            return Storage::disk('railway_volume');
        }
        
        return Storage::disk('public');
    }
    
    public static function isRailwayWithVolume()
    {
        $volumePath = env('RAILWAY_VOLUME_PATH');
        
        return env('RAILWAY_ENVIRONMENT') === 'production' 
            && $volumePath 
            && file_exists($volumePath);
    }
    
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
    
    public static function getPublicUrl($path)
    {
        if (empty($path)) {
            return null;
        }
        
        // FORCE FIX: Extract relative path from any URL
        if (strpos($path, '/storage/') !== false) {
            $parts = explode('/storage/', $path, 2);
            $path = $parts[1] ?? $path;
        }
        
        // Remove any leading slashes
        $cleanPath = ltrim($path, '/');
        
        // On Railway, always use the absolute URL with /api/storage/
        if (self::isRailwayWithVolume()) {
            $baseUrl = 'https://be-ktx-production.up.railway.app';
            return $baseUrl . '/api/storage/' . $cleanPath;
        }
        
        // Local development
        return url('/storage/' . $cleanPath);
    }
    
    public static function getFullPath($path)
    {
        if (empty($path)) {
            return null;
        }
        
        $disk = self::getStorageDisk();
        return $disk->path($path);
    }
}