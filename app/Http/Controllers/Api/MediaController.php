<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Traits\HandlesApiErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller
{
    use HandlesApiErrors;

    /**
     * Upload a media file (image or video)
     */
    public function upload(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,gif,webp,mp4,webm,ogg,mov,avi|max:51200', // 50MB max for videos
        ]);

        try {
            $file = $request->file('file');
            $path = $file->store('media/' . ($user->company_id ?? 'general'), 'public');
            
            // Generate full URL
            $imageUrl = Storage::disk('public')->url($path);
            // If the URL is relative, make it absolute
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
                'user_id' => $user->id,
            ]);
            return response()->json([
                'message' => 'Failed to upload media: ' . $e->getMessage(),
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
                            // If the URL is relative, make it absolute
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
                            // If the URL is relative, make it absolute
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
