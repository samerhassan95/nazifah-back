<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadFilesService
{
    /**
     * Upload an image file
     */
    public function uploadImage(UploadedFile $image, string $path, ?string $oldImage = null): string
    {
        if ($oldImage) {
            $this->deleteFile($oldImage);
        }

        $filename = $this->generateFilename($image);
        $storagePath = $image->storeAs($path, $filename, 'public');

        return '/storage/'.$storagePath;
    }

    /**
     * Upload a file (document, pdf, etc.)
     */
    public function uploadFile(UploadedFile $file, string $path, ?string $oldFile = null): string
    {
        // Delete old file if exists
        if ($oldFile) {
            $this->deleteFile($oldFile);
        }

        $filename = $this->generateFilename($file);
        $storagePath = $file->storeAs($path, $filename, 'public');

        return '/storage/'.$storagePath;
    }

    /**
     * Upload a logo
     */
    public function uploadLogo(UploadedFile $logo, string $path = 'logos', ?string $oldLogo = null): string
    {
        return $this->uploadImage($logo, $path, $oldLogo);
    }

    /**
     * Upload avatar/profile image
     */
    public function uploadAvatar(UploadedFile $avatar, string $path = 'avatars', ?string $oldAvatar = null): string
    {
        return $this->uploadImage($avatar, $path, $oldAvatar);
    }

    /**
     * Upload chat file
     */
    public function uploadChatFile(UploadedFile $file, ?string $conversationId = null): string
    {
        $path = $conversationId ? "chat/files/{$conversationId}" : 'chat/files';

        return $this->uploadFile($file, $path);
    }

    /**
     * Upload multiple images
     */
    public function uploadMultipleImages(array $images, string $path): array
    {
        $paths = [];
        foreach ($images as $image) {
            if ($image instanceof UploadedFile) {
                $paths[] = $this->uploadImage($image, $path);
            }
        }

        return $paths;
    }

    /**
     * Upload multiple files
     */
    public function uploadMultipleFiles(array $files, string $path): array
    {
        $paths = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $paths[] = $this->uploadFile($file, $path);
            }
        }

        return $paths;
    }

    /**
     * Delete a file
     */
    public function deleteFile(string $filePath): bool
    {
        // Convert full URL to relative path
        $relativePath = $this->getRelativePath($filePath);

        if (Storage::disk('public')->exists($relativePath)) {
            return Storage::disk('public')->delete($relativePath);
        }

        return false;
    }

    /**
     * Delete multiple files
     */
    public function deleteMultipleFiles(array $filePaths): bool
    {
        foreach ($filePaths as $filePath) {
            $this->deleteFile($filePath);
        }

        return true;
    }

    /**
     * Get full URL for a file path
     */
    public function getFullUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // If already a full URL, return as is
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return url($path);
    }

    /**
     * Get relative path from full URL or storage path
     */
    public function getRelativePath(string $path): string
    {
        // Remove domain if present
        $path = preg_replace('/^https?:\/\/[^\/]+/', '', $path);

        // Remove /storage/ prefix
        $path = str_replace('/storage/', '', $path);

        return $path;
    }

    /**
     * Generate unique filename
     */
    private function generateFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid().'.'.$extension;

        return $filename;
    }

    /**
     * Check if file exists
     */
    public function fileExists(string $filePath): bool
    {
        $relativePath = $this->getRelativePath($filePath);

        return Storage::disk('public')->exists($relativePath);
    }

    /**
     * Get file size in bytes
     */
    public function getFileSize(string $filePath): ?int
    {
        $relativePath = $this->getRelativePath($filePath);

        if (Storage::disk('public')->exists($relativePath)) {
            return Storage::disk('public')->size($relativePath);
        }

        return null;
    }

    /**
     * Get file mime type
     */
    public function getMimeType(string $filePath): ?string
    {
        $relativePath = $this->getRelativePath($filePath);

        if (Storage::disk('public')->exists($relativePath)) {
            try {
                // Get the full path to the file
                $fullPath = Storage::disk('public')->path($relativePath);

                // Use PHP's native mime_content_type function
                if (file_exists($fullPath)) {
                    return mime_content_type($fullPath) ?: null;
                }
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Move file to another location
     */
    public function moveFile(string $from, string $to): bool
    {
        $fromPath = $this->getRelativePath($from);
        $toPath = $this->getRelativePath($to);

        if (Storage::disk('public')->exists($fromPath)) {
            return Storage::disk('public')->move($fromPath, $toPath);
        }

        return false;
    }

    /**
     * Copy file to another location
     */
    public function copyFile(string $from, string $to): bool
    {
        $fromPath = $this->getRelativePath($from);
        $toPath = $this->getRelativePath($to);

        if (Storage::disk('public')->exists($fromPath)) {
            return Storage::disk('public')->copy($fromPath, $toPath);
        }

        return false;
    }

    /**
     * Upload a base64 encoded image
     */
    public function uploadBase64Image(string $base64String, string $path, ?string $oldImage = null): string
    {
        // Delete old image if exists
        if ($oldImage) {
            $this->deleteFile($oldImage);
        }

        // Extract image data and extension
        if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            $base64String = substr($base64String, strpos($base64String, ',') + 1);
            $extension = strtolower($type[1]); // png, jpg, etc
        } else {
            // Default extension if no header provided
            $extension = 'png';
        }

        $imageContent = base64_decode($base64String);
        if ($imageContent === false) {
            throw new \Exception('Invalid base64 string');
        }

        $filename = Str::uuid().'.'.$extension;
        $storagePath = $path.'/'.$filename;

        Storage::disk('public')->put($storagePath, $imageContent);

        return '/storage/'.$storagePath;
    }
}
