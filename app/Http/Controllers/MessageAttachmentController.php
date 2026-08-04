<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\Auth\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated private-disk stream + staging upload for message attachments.
 * No public URLs — S10 invariant honoured at the route level.
 */
class MessageAttachmentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        Gate::authorize(Permission::InboxSend->value);

        $maxBytes = (int) config('communications.inbound.max_attachment_bytes');

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) ceil($maxBytes / 1024)],
        ]);

        $file = $request->file('file');
        if ($file === null) {
            return $this->error('File is required.', ['file' => ['File is required.']], 422);
        }

        if ($file->getSize() > $maxBytes) {
            return $this->error(
                'Attachment exceeds the maximum size.',
                ['file' => ['Maximum size is '.$maxBytes.' bytes.']],
                422,
            );
        }

        $uuid = (string) Str::uuid();
        $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ?: 'attachment';
        $extension = $file->getClientOriginalExtension();
        $filename = $extension !== '' ? $safeName.'.'.$extension : $safeName;
        $path = 'message-attachments/staging/'.$uuid.'/'.$filename;

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()) ?: '');

        $attachment = MessageAttachment::query()->create([
            'message_id' => null,
            'filename' => $file->getClientOriginalName() !== ''
                ? $file->getClientOriginalName()
                : $filename,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => (int) $file->getSize(),
            'oversize' => false,
            'disk_path' => $path,
        ]);

        return $this->created([
            'id' => $attachment->id,
        ], 'Attachment staged.');
    }

    public function download(MessageAttachment $messageAttachment): StreamedResponse|JsonResponse
    {
        Gate::authorize(Permission::InboxView->value);

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
