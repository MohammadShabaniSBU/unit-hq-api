<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\MessageAttachment;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsCommunicationAccounts;
use Tests\Support\SeedsInboxThreads;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCommunicationAccounts;
    use SeedsInboxThreads;

    public function test_stage_link_sweep_caps(): void
    {
        Storage::fake('local');

        $site = Site::factory()->create();
        $this->seedEmailAccount($site);

        $employee = Employee::factory()->manager()->create();
        Sanctum::actingAs($employee);

        $contact = Contact::factory()->create(['email' => 'files@example.com']);
        $this->givePrimaryEmail($contact, 'files@example.com');
        $thread = $this->makeInboxThread($contact, ['subject' => 'Files']);

        $max = (int) config('communications.inbound.max_attachment_bytes');

        $oversize = $this->post('/api/inbox/attachments', [
            'file' => UploadedFile::fake()->create('huge.bin', (int) ceil(($max + 1024) / 1024)),
        ]);
        $oversize->assertStatus(422);

        $stage = $this->post('/api/inbox/attachments', [
            'file' => UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'),
        ]);
        $stage->assertCreated();
        $attachmentId = (int) $stage->json('data.id');

        $staged = MessageAttachment::query()->findOrFail($attachmentId);
        $this->assertNull($staged->message_id);
        $this->assertNotNull($staged->disk_path);
        Storage::disk('local')->assertExists($staged->disk_path);

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-att'], 201),
        ]);

        $reply = $this->postJson('/api/inbox/threads/'.$thread->id.'/reply', [
            'body_text' => 'See attached.',
            'attachment_ids' => [$attachmentId],
        ]);
        $reply->assertCreated();

        $staged->refresh();
        $this->assertSame($reply->json('data.message.id'), $staged->message_id);

        // Orphan sweep: stage another file, age it, sweep.
        $orphanStage = $this->post('/api/inbox/attachments', [
            'file' => UploadedFile::fake()->create('orphan.txt', 10, 'text/plain'),
        ]);
        $orphanStage->assertCreated();
        $orphanId = (int) $orphanStage->json('data.id');
        $orphan = MessageAttachment::query()->findOrFail($orphanId);
        $orphanPath = $orphan->disk_path;
        $orphan->forceFill([
            'created_at' => now()->subHours((int) config('communications.staging.orphan_ttl_hours', 24) + 1),
        ])->save();

        Artisan::call('comms:sweep-orphan-attachments');

        $this->assertNull(MessageAttachment::query()->find($orphanId));
        if (is_string($orphanPath)) {
            Storage::disk('local')->assertMissing($orphanPath);
        }

        // Linked attachment survives sweep.
        $this->assertNotNull(MessageAttachment::query()->find($attachmentId));
    }
}
