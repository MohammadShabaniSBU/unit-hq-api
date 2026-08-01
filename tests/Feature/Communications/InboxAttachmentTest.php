<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class InboxAttachmentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsInboxThreads;

    public function test_download_auth_stream_and_missing(): void
    {
        Storage::fake('local');

        $contact = Contact::factory()->create();
        $thread = $this->makeInboxThread($contact);
        $message = Message::query()->where('message_thread_id', $thread->id)->firstOrFail();

        $path = 'message-attachments/1/invoice.pdf';
        Storage::disk('local')->put($path, 'pdf-bytes');

        $attachment = MessageAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            'oversize' => false,
            'disk_path' => $path,
        ]);

        $this->getJson("/api/message-attachments/{$attachment->id}/download")
            ->assertUnauthorized();

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $this->get("/api/message-attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertHeader('content-disposition');

        $oversize = MessageAttachment::query()->create([
            'message_id' => $message->id,
            'filename' => 'huge.bin',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 99_000_000,
            'oversize' => true,
            'disk_path' => null,
        ]);

        $this->getJson("/api/message-attachments/{$oversize->id}/download")
            ->assertNotFound();
    }
}
