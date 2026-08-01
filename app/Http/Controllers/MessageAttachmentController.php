<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated private-disk stream for message attachments.
 * No public URLs — S10 invariant honoured at the route level.
 */
class MessageAttachmentController extends Controller
{
    public function download(MessageAttachment $messageAttachment): StreamedResponse|JsonResponse
    {
        $path = $messageAttachment->disk_path;

        if ($path === null || $path === '' || $messageAttachment->oversize) {
            return $this->notFound('Attachment not available.');
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return $this->notFound('Attachment not available.');
        }

        return $disk->download(
            $path,
            $messageAttachment->filename,
            [
                'Content-Type' => $messageAttachment->mime_type,
            ],
        );
    }
}
