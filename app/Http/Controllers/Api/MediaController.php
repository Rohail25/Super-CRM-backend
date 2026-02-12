<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class MediaController extends Controller
{
    use HandlesApiErrors;

    /**
     * Upload a media file (image or video)
     */
    public function upload(Request $request)
    {
        $user = $request->user();
        
        // Check if file was actually uploaded
        if (!$request->hasFile('file')) {
            Log::error('Media upload failed: No file in request', [
                'user_id' => $user->id,
                'request_keys' => array_keys($request->all()),
                'php_upload_errors' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'max_file_uploads' => ini_get('max_file_uploads'),
                ],
            ]);
            
            return response()->json([
                'message' => 'No file was uploaded. Please check file size limits and try again.',
                'errors' => [
                    'file' => [
                        'The file failed to upload. This may be due to file size limits or server configuration.',
                    ],
                ],
                'server_limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'max_file_uploads' => ini_get('max_file_uploads'),
                ],
            ], 422);
        }

        try {
            $validated = $request->validate([
                'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,mp4,webm,ogg,mov,avi|max:51200', // 50MB max for videos
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Media upload validation failed', [
                'user_id' => $user->id,
                'errors' => $e->errors(),
                'file_info' => $request->hasFile('file') ? [
                    'name' => $request->file('file')->getClientOriginalName(),
                    'size' => $request->file('file')->getSize(),
                    'mime' => $request->file('file')->getMimeType(),
                ] : null,
            ]);
            
            return response()->json([
                'message' => 'File validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            $file = $request->file('file');
            
            // Check if storage directory is writable
            $storagePath = storage_path('app/public/media/' . ($user->company_id ?? 'general'));
            if (!is_dir($storagePath)) {
                File::makeDirectory($storagePath, 0755, true);
            }
            
            if (!is_writable($storagePath)) {
                Log::error('Media upload failed: Storage directory not writable', [
                    'user_id' => $user->id,
                    'path' => $storagePath,
                    'permissions' => substr(sprintf('%o', fileperms($storagePath)), -4),
                ]);
                
                return response()->json([
                    'message' => 'Storage directory is not writable. Please contact administrator.',
                    'errors' => [
                        'file' => ['The file failed to upload due to server configuration.'],
                    ],
                ], 500);
            }
            
            $path = $file->store('media/' . ($user->company_id ?? 'general'), 'public');
            
            if (!$path) {
                Log::error('Media upload failed: File store returned false', [
                    'user_id' => $user->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                ]);
                
                return response()->json([
                    'message' => 'Failed to save file to storage.',
                    'errors' => [
                        'file' => ['The file failed to upload.'],
                    ],
                ], 500);
            }
            
            // Generate full URL - ensure it's always absolute
            $imageUrl = Storage::disk('public')->url($path);
            
            // If the URL is relative (starts with /storage), make it absolute using APP_URL
            if (strpos($imageUrl, '/') === 0 && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $baseUrl = rtrim(config('app.url'), '/');
                $imageUrl = $baseUrl . $imageUrl;
            }
            
            // Final check: if still not a valid URL, use url() helper
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imageUrl = url($imageUrl);
            }

            Log::info('Media uploaded', [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'path' => $path,
                'url' => $imageUrl,
            ]);

            // Determine file type
            $mimeType = $file->getMimeType();
            $isVideo = strpos($mimeType, 'video/') === 0;
            
            return response()->json([
                'url' => $imageUrl,
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'type' => $isVideo ? 'video' : 'image',
                'mimeType' => $mimeType,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Media upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
                'file_info' => $request->hasFile('file') ? [
                    'name' => $request->file('file')->getClientOriginalName(),
                    'size' => $request->file('file')->getSize(),
                    'mime' => $request->file('file')->getMimeType(),
                ] : null,
                'php_limits' => [
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                ],
            ]);
            
            return response()->json([
                'message' => 'The file failed to upload.',
                'errors' => [
                    'file' => [
                        'The file failed to upload. Error: ' . $e->getMessage(),
                    ],
                ],
            ], 500);
        }
    }

    /**
     * List all media files
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $mediaItems = [];

        try {
            // Get media from campaigns
            $campaignQuery = \App\Models\Campaign::whereNotNull('image_path')
                ->orderBy('created_at', 'desc')
                ->limit(50);
            
            if ($user->isSuperAdmin()) {
                $campaignQuery->withoutGlobalScope('company');
            } else {
                $campaignQuery->where('company_id', $user->company_id);
            }
            
            $campaigns = $campaignQuery->get();

            foreach ($campaigns as $campaign) {
                $imageUrl = null;
                
                // Check image_path first
                if (!empty($campaign->image_path)) {
                    try {
                        // Check if file exists before adding to list
                        if (Storage::disk('public')->exists($campaign->image_path)) {
                            $imageUrl = Storage::disk('public')->url($campaign->image_path);
                            
                            // If the URL is relative (starts with /storage), make it absolute using APP_URL
                            if (strpos($imageUrl, '/') === 0 && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                                $baseUrl = rtrim(config('app.url'), '/');
                                $imageUrl = $baseUrl . $imageUrl;
                            }
                            
                            // Final check: if still not a valid URL, use url() helper
                            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                                $imageUrl = url($imageUrl);
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip if file doesn't exist
                    }
                }
                
                // If no image_path, check settings for imageUrl
                if (!$imageUrl && !empty($campaign->settings)) {
                    $settings = is_string($campaign->settings) ? json_decode($campaign->settings, true) : $campaign->settings;
                    if (isset($settings['imageUrl']) && !empty($settings['imageUrl'])) {
                        $imageUrl = $settings['imageUrl'];
                    }
                }
                
                if ($imageUrl) {
                    try {
                        $mediaItems[] = [
                            'id' => $campaign->id,
                            'url' => $imageUrl,
                            'name' => $campaign->name || 'Campaign Image',
                            'created_at' => $campaign->created_at ? ($campaign->created_at instanceof \Carbon\Carbon ? $campaign->created_at->toDateTimeString() : $campaign->created_at) : null,
                        ];
                    } catch (\Exception $e) {
                        // Skip invalid images silently
                        Log::debug('Skipping invalid campaign image', [
                            'campaign_id' => $campaign->id,
                            'image_path' => $campaign->image_path,
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }
            }

            // Also get media from media directory
            $mediaPath = 'media/' . ($user->company_id ?? 'general');
            try {
                if (Storage::disk('public')->exists($mediaPath)) {
                    $files = Storage::disk('public')->files($mediaPath);
                    foreach ($files as $file) {
                        try {
                            // Only process image and video files
                            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg', 'mov', 'avi'])) {
                                continue;
                            }
                            
                            // Check if file exists
                            if (!Storage::disk('public')->exists($file)) {
                                continue;
                            }
                            
                            // Determine file type
                            $mimeType = Storage::disk('public')->mimeType($file);
                            $isVideo = $mimeType && strpos($mimeType, 'video/') === 0;
                            
                            $imageUrl = Storage::disk('public')->url($file);
                            
                            // If the URL is relative (starts with /storage), make it absolute using APP_URL
                            if (strpos($imageUrl, '/') === 0 && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                                $baseUrl = rtrim(config('app.url'), '/');
                                $imageUrl = $baseUrl . $imageUrl;
                            }
                            
                            // Final check: if still not a valid URL, use url() helper
                            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                                $imageUrl = url($imageUrl);
                            }

                            $mediaItems[] = [
                                'id' => 'file_' . md5($file),
                                'url' => $imageUrl,
                                'name' => basename($file),
                                'type' => $isVideo ? 'video' : 'image',
                                'mimeType' => $mimeType,
                                'created_at' => Storage::disk('public')->lastModified($file) 
                                    ? date('Y-m-d H:i:s', Storage::disk('public')->lastModified($file))
                                    : null,
                            ];
                        } catch (\Exception $e) {
                            // Skip invalid files silently
                            continue;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Directory doesn't exist or can't be accessed - that's okay
                Log::info('Media directory not accessible', [
                    'path' => $mediaPath,
                    'error' => $e->getMessage(),
                ]);
            }

            // Remove duplicates based on URL
            $uniqueItems = [];
            $seenUrls = [];
            foreach ($mediaItems as $item) {
                if (!in_array($item['url'], $seenUrls)) {
                    $uniqueItems[] = $item;
                    $seenUrls[] = $item['url'];
                }
            }

            return response()->json($uniqueItems);
        } catch (\Exception $e) {
            Log::error('Failed to list media', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user->id,
            ]);
            // Return empty array on error instead of failing
            return response()->json([]);
        }
    }
}
