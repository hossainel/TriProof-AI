<?php

namespace Tests\Feature;

use App\Models\DocRevision;
use App\Models\DocSection;
use App\Models\DocTeamMember;
use App\Models\Factory;
use App\Models\PurchaseOrder;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DocsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocsSeeder::class);
    }

    public function test_public_docs_is_available_and_shows_live_database_metrics(): void
    {
        SystemSetting::setValue('docs_visibility_enabled', '1');
        SystemSetting::setValue('docs_schedule_enabled', '0');

        Factory::create(['code' => 'DOC01', 'name_en' => 'Doc Factory One', 'is_active' => true]);
        Factory::create(['code' => 'DOC02', 'name_en' => 'Doc Factory Two', 'is_active' => true]);
        PurchaseOrder::factory()->create(['authorized_quota' => 123]);

        $response = $this->get(route('docs.index'));

        $response->assertOk();
        $response->assertSee('Active Factories');
        $response->assertSee('Purchase Orders');
        $response->assertSee('Signed Serials');

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/Active Factories<\/p>\s*<p[^>]*>2<\/p>/', $html);
        $this->assertMatchesRegularExpression('/Purchase Orders<\/p>\s*<p[^>]*>1<\/p>/', $html);
    }

    public function test_visibility_toggle_and_admin_preview_bypass(): void
    {
        SystemSetting::setValue('docs_visibility_enabled', '0');
        SystemSetting::setValue('docs_schedule_enabled', '0');

        $this->get(route('docs.index'))
            ->assertForbidden()
            ->assertSee('Documentation Unavailable');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('docs.index', ['preview' => 1]))
            ->assertOk()
            ->assertSee('Admin Preview Mode');
    }

    public function test_scheduled_availability_window_is_enforced(): void
    {
        SystemSetting::setValue('docs_visibility_enabled', '1');
        SystemSetting::setValue('docs_schedule_enabled', '1');
        SystemSetting::setValue('docs_available_from', '2026-06-10 00:00:00');
        SystemSetting::setValue('docs_available_until', '2026-06-14 23:59:59');

        Carbon::setTestNow(Carbon::parse('2026-06-12 10:00:00', config('app.timezone')));
        $this->get(route('docs.index'))->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-09 23:59:59', config('app.timezone')));
        $this->get(route('docs.index'))->assertForbidden();

        Carbon::setTestNow(Carbon::parse('2026-06-15 00:00:01', config('app.timezone')));
        $this->get(route('docs.index'))->assertForbidden();
    }

    public function test_admin_can_manage_settings_sections_revisions_and_team(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->put(route('admin.docs.settings.update'), [
            'docs_visibility_enabled' => '0',
            'docs_schedule_enabled' => '1',
            'docs_available_from' => '2026-07-01 00:00:00',
            'docs_available_until' => '2026-07-05 23:59:59',
            'docs_team_name' => 'TriProof Evaluation Team',
        ])->assertRedirect();

        $this->assertSame('0', SystemSetting::getValue('docs_visibility_enabled'));
        $this->assertSame('1', SystemSetting::getValue('docs_schedule_enabled'));
        $this->assertSame('TriProof Evaluation Team', SystemSetting::getValue('docs_team_name'));

        $section = DocSection::where('slug', 'problem')->firstOrFail();

        $this->put(route('admin.docs.sections.update', $section), [
            'title_en' => 'Original Supply-Chain Risks',
            'title_bn' => 'মূল সরবরাহ চেইন ঝুঁকি',
            'category' => 'pitch_deck',
            'content_markdown' => 'Original published body.',
            'draft_markdown' => 'New published body with **proof**.',
            'diagram_mermaid' => 'graph TD; A-->B;',
            'is_visible' => 1,
            'action' => 'publish',
            'change_note' => 'Initial publish',
        ])->assertRedirect();

        $section->refresh();
        $this->assertSame('Original Supply-Chain Risks', $section->title_en);
        $this->assertStringContainsString('New published body', $section->content_markdown);
        $this->assertSame($admin->id, $section->updated_by);

        $firstRevision = DocRevision::latest()->firstOrFail();
        $this->assertStringStartsWith('v2.1.0-r', $firstRevision->version_label);

        $otherSection = DocSection::where('slug', 'solution')->firstOrFail();
        $this->post(route('admin.docs.reorder'), [
            'order' => [$otherSection->id, $section->id],
        ])->assertOk();

        $this->assertSame(10, $otherSection->fresh()->sort_order);
        $this->assertSame(20, $section->fresh()->sort_order);

        $this->put(route('admin.docs.sections.update', $section), [
            'title_en' => 'Changed Risks Title',
            'category' => 'pitch_deck',
            'content_markdown' => 'Changed body.',
            'is_visible' => 1,
            'action' => 'publish',
            'change_note' => 'Second publish',
        ])->assertRedirect();

        $this->post(route('admin.docs.revisions.restore', $firstRevision))->assertRedirect();
        $this->assertSame('Original Supply-Chain Risks', $section->fresh()->title_en);

        $photo = UploadedFile::fake()->image('member.jpg', 400, 200);
        $this->post(route('admin.docs.team.store'), [
            'full_name' => 'Docs Test Member',
            'role_title' => 'Verification Engineer',
            'email' => 'docs-member@example.test',
            'bio_short' => 'Test bio',
            'photo' => $photo,
            'is_active' => 1,
        ])->assertRedirect();

        $member = DocTeamMember::where('email', 'docs-member@example.test')->firstOrFail();
        $this->assertNotNull($member->photo_path);

        $uploadedPath = public_path('uploads/team/'.$member->photo_path);
        $this->assertFileExists($uploadedPath);
        [$width, $height] = getimagesize($uploadedPath);
        $this->assertSame(256, $width);
        $this->assertSame(256, $height);

        $this->put(route('admin.docs.team.update', $member), [
            'full_name' => 'Docs Updated Member',
            'role_title' => 'Lead Verification Engineer',
            'email' => 'docs-member@example.test',
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame('Docs Updated Member', $member->fresh()->full_name);

        $this->delete(route('admin.docs.team.destroy', $member))->assertRedirect();
        $this->assertDatabaseMissing('doc_team_members', ['email' => 'docs-member@example.test']);
        File::delete($uploadedPath);
    }

    public function test_exports_work_when_accessible_and_are_blocked_when_disabled(): void
    {
        SystemSetting::setValue('docs_visibility_enabled', '1');
        SystemSetting::setValue('docs_schedule_enabled', '0');

        $this->get(route('docs.export.pdf'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $markdown = $this->get(route('docs.export.markdown'))->assertOk();
        $this->assertStringContainsString('TriProof AI Documentation', $markdown->streamedContent());
        $markdown->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');

        SystemSetting::setValue('docs_visibility_enabled', '0');

        $this->get(route('docs.export.pdf'))->assertForbidden();
        $this->get(route('docs.export.markdown'))->assertForbidden();
    }
}
