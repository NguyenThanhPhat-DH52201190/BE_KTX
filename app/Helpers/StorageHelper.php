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
        $result = env('RAILWAY_ENVIRONMENT') === 'production' 
            && $volumePath 
            && file_exists($volumePath);
        
        // Log the check
        Log::info('isRailwayWithVolume check', [
            'railway_environment' => env('RAILWAY_ENVIRONMENT'),
            'volume_path' => $volumePath,
            'volume_exists' => $volumePath ? file_exists($volumePath) : false,
            'result' => $result
        ]);
        
        return $result;
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
        // Log the input
        Log::info('===== getPublicUrl START =====');
        Log::info('Input path', [
            'original_path' => $path,
            'path_type' => gettype($path),
            'is_empty' => empty($path)
        ]);
        
        if (empty($path)) {
            Log::info('Path is empty, returning null');
            return null;
        }
        
        // Check if it contains /storage/
        $hasStorage = strpos($path, '/storage/') !== false;
        Log::info('Path analysis', [
            'contains_storage_slash' => $hasStorage,
            'contains_http' => strpos($path, 'http') === 0,
            'is_url' => filter_var($path, FILTER_VALIDATE_URL)
        ]);
        
        // If it's a full URL, extract just the relative path
        if ($hasStorage) {
            $originalPath = $path;
            $parts = explode('/storage/', $path, 2);
            $path = $parts[1] ?? $path;
            Log::info('Extracted relative path', [
                'original' => $originalPath,
                'extracted_path' => $path,
                'extraction_success' => isset($parts[1])
            ]);
        } else {
            Log::info('Path does not contain /storage/, keeping as-is', ['path' => $path]);
        }
        
        // Clean the path
        $cleanPath = ltrim($path, '/');
        Log::info('Cleaned path', ['clean_path' => $cleanPath]);
        
        // Check Railway volume status
        $isRailway = self::isRailwayWithVolume();
        Log::info('Railway status', [
            'is_railway_with_volume' => $isRailway,
            'will_use_railway_logic' => $isRailway
        ]);
        
        // If using Railway volume, return API endpoint URL
        if ($isRailway) {
            $generatedUrl = url('/api/storage/' . $cleanPath);
            Log::info('Generated Railway URL', [
                'base_url' => url('/'),
                'constructed_url' => $generatedUrl,
                'final_url' => $generatedUrl
            ]);
            Log::info('===== getPublicUrl END =====');
            return $generatedUrl;
        }
        
        // Local development - use storage URL
        $localUrl = Storage::url($cleanPath);
        Log::info('Generated local URL', [
            'storage_url' => $localUrl,
            'final_url' => $localUrl
        ]);
        Log::info('===== getPublicUrl END =====');
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