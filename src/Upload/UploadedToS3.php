<?php

namespace Bref\LaravelBridge\Upload;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Validates that a client-supplied key references a file that was uploaded to S3
 * via the signed upload URL endpoint, by the current user (or by a guest when there is no user).
 *
 * Usage:
 *
 *     $request->validate([
 *         'document' => ['required', new UploadedToS3(extensions: ['pdf'], maxSize: 10 * 1024 * 1024)],
 *         'attachment' => ['nullable', new UploadedToS3(extensions: null)],
 *     ]);
 */
class UploadedToS3 implements ValidationRule
{
    /**
     * @param list<string>|null $extensions Allowed file extensions, e.g. `['pdf', 'png']`. The extension is derived
     *     from the client-declared content type; file contents are not inspected. Pass `null` to accept any
     *     extension. Serve untrusted uploads from a separate origin or as attachments.
     * @param int|null $maxSize Maximum file size in bytes (null = `bref.uploads.max_size`).
     * @param string|null $disk The disk to check (null = the disk configured in `bref.uploads.disk`).
     */
    public function __construct(
        private readonly ?array $extensions,
        private readonly ?int $maxSize = null,
        private readonly ?string $disk = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute is not a valid upload.');
            return;
        }

        // Authenticated users can only reference their own uploads, guests only guest uploads
        if (! preg_match($this->pattern(Auth::user()), $value)) {
            $fail('The :attribute is not a valid upload.');
            return;
        }

        $storage = Storage::disk($this->disk ?? Uploads::disk());

        if (! $storage->exists($value)) {
            $fail('The :attribute was not found. Please upload it again.');
            return;
        }

        $maxSize = $this->maxSize ?? config('bref.uploads.max_size', 50 * 1024 * 1024);
        if ($maxSize !== null && $storage->size($value) > (int) $maxSize) {
            $fail('The :attribute exceeds the maximum size of ' . self::formatBytes((int) $maxSize) . '.');
        }
    }

    private function pattern(?Authenticatable $user): string
    {
        $directory = preg_quote(Uploads::directory($user) . '/', '/');

        if ($this->extensions !== null) {
            $extensions = implode('|', array_map(fn (string $extension) => preg_quote($extension, '/'), $this->extensions));
            $extensionPattern = "\.($extensions)";
        } else {
            $extensionPattern = '(\.[a-z0-9]+(\.[a-z0-9]+)*)?';
        }

        return "/^{$directory}[0-9a-f-]{36}{$extensionPattern}$/i";
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return "$bytes bytes";
    }
}
