<?php

namespace Tests\Feature;

use App\Models\{FlightDocument, FlightSegment, InternalApiToken, RosterItem, SyncRun, User};
use App\Services\FlightDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Artisan, DB, Storage};
use Tests\TestCase;

class FlightDocumentsTest extends TestCase
{
    private $user;
    private $segment;
    private $run;
    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('flight_documents');
        $this->user = User::create(['status' => 'active', 'role' => 'admin']);
        $ring = RosterItem::create(['user_id' => $this->user->id, 'starts_at' => now(), 'ends_at' => now()->addDays(3)]);
        $this->segment = FlightSegment::create(['user_id' => $this->user->id, 'roster_item_id' => $ring->id,
            'source' => 'rossiya_edu', 'flight_number' => 'FV123', 'starts_at' => now(), 'ends_at' => now()->addHours(2),
            'download_doc_url' => 'https://portal.example/epz.pdf', 'ofp_url' => 'https://portal.example/ofp.pdf']);
        $this->run = SyncRun::create(['user_id' => $this->user->id, 'source' => 'rossiya_edu', 'trigger' => 'scheduler',
            'status' => 'running', 'task_type' => 'flight_details', 'roster_item_id' => $ring->id,
            'started_at' => now(), 'lock_expires_at' => now()->addMinutes(15)]);
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('document-test'), 'is_active' => true]);
    }

    private function upload(?string $bytes = self::PDF, array $extra = [])
    {
        $data = ['flight_number' => 'FV123', 'starts_at' => $this->segment->starts_at->toIso8601String(),
            'document_type' => 'epz', 'source_url' => $this->segment->download_doc_url];
        if ($bytes === null) { $data['failed'] = '1'; }
        else { $data['file'] = UploadedFile::fake()->createWithContent('document.pdf', $bytes); }
        return $this->withToken('document-test')->post('/api/internal/sync-runs/'.$this->run->id.'/documents',
            array_merge($data, $extra), ['Accept' => 'application/json']);
    }

    public function test_upload_replaces_only_changed_content_and_keeps_previous_copy_on_failure(): void
    {
        $this->upload()->assertOk();
        $original = FlightDocument::first();
        $this->assertTrue($original->expires_at->equalTo($this->segment->rosterItem->ends_at->addDays(7)));
        $this->travel(1)->minutes();
        $this->upload()->assertOk();
        $this->assertSame($original->storage_path, $original->fresh()->storage_path);
        $this->assertTrue($original->content_updated_at->equalTo($original->fresh()->content_updated_at));
        $this->assertTrue($original->fresh()->last_checked_at->gt($original->last_checked_at));
        $this->upload(null)->assertOk();
        $this->assertNotNull($original->fresh()->last_error_at);
        $this->upload('<html>login</html>')->assertUnprocessable();
        Storage::disk('flight_documents')->assertExists($original->storage_path);
        $this->upload(str_replace('1.4', '1.5', self::PDF))->assertOk();
        Storage::disk('flight_documents')->assertMissing($original->storage_path);
        $this->assertCount(1, Storage::disk('flight_documents')->allFiles());
        $this->assertNull($original->fresh()->last_error_at);
    }

    public function test_documents_require_owner_and_permission_and_expire_before_cleanup(): void
    {
        $url = '/api/workplan/flights/'.$this->segment->id.'/documents/epz';
        $this->getJson($url)->assertUnauthorized();
        $this->upload()->assertOk();
        $other = User::create(['status' => 'active', 'role' => 'admin']);
        $this->actingAs($other)->getJson($url)->assertNotFound();
        $this->actingAs($this->user)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Disposition', 'inline; filename="EPZ-'.$this->segment->id.'.pdf"');
        $this->get($url.'?download=1')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="EPZ-'.$this->segment->id.'.pdf"');
        $this->user->update(['status' => 'blocked']);
        $this->getJson($url)->assertForbidden();
        $this->user->update(['status' => 'active']);
        $this->travel(11)->days();
        $this->getJson($url)->assertNotFound();
        $path = FlightDocument::first()->storage_path;
        Artisan::call('flight-documents:prune');
        Storage::disk('flight_documents')->assertMissing($path);
        $this->assertNull(FlightDocument::first()->storage_path);
        $this->assertSame('https://portal.example/epz.pdf', FlightDocument::first()->source_url);
        $this->segment->update(['download_doc_url' => null]);
        $metadata = app(FlightDocumentService::class)->metadata($this->segment->fresh());
        $this->assertSame('https://portal.example/epz.pdf', $metadata[0]['source_download_url']);
        $this->assertFalse($metadata[0]['available']);
    }

    public function test_source_run_scope_and_ring_extension_are_respected(): void
    {
        $this->upload(null, ['source_url' => 'https://other.example/doc'])->assertConflict();
        $this->upload(null, ['flight_number' => 'OTHER'])->assertNotFound();
        $this->upload()->assertOk();
        $ring = $this->segment->rosterItem;
        $ring->update(['ends_at' => now()->addDays(10)]);
        $this->travel(11)->days();
        Artisan::call('flight-documents:prune');
        $doc = FlightDocument::first();
        Storage::disk('flight_documents')->assertExists($doc->storage_path);
        $this->assertTrue(app(FlightDocumentService::class)->available($doc, $this->segment->fresh()));
        $this->upload()->assertConflict(); // The parser lease expired.
    }

    public function test_expired_files_are_not_downloaded_again(): void
    {
        $this->segment->rosterItem->update(['ends_at' => now()->subDays(8)]);
        $this->segment->update(['ends_at' => now()->subDays(8)]);
        $this->upload()->assertOk();
        $this->assertDatabaseCount('flight_documents', 0);
        $this->assertCount(0, Storage::disk('flight_documents')->allFiles());
    }
}
