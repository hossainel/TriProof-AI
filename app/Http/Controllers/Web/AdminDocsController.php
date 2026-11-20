<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DocRevision;
use App\Models\DocSection;
use App\Models\DocTeamMember;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminDocsController extends Controller
{
    private const SECTION_FIELDS = [
        'slug',
        'category',
        'section_key',
        'title_en',
        'title_bn',
        'subtitle_en',
        'subtitle_bn',
        'content_markdown',
        'draft_markdown',
        'diagram_mermaid',
        'sort_order',
        'is_visible',
        'status',
        'updated_by',
    ];

    private const DOCS_SETTING_KEYS = [
        'docs_visibility_enabled',
        'docs_schedule_enabled',
        'docs_available_from',
        'docs_available_until',
        'docs_team_name',
    ];

    public function index()
    {
        return view('admin.docs.index', [
            'sections' => DocSection::orderBy('sort_order')->get(),
            'team' => DocTeamMember::orderBy('sort_order')->get(),
            'revisions' => DocRevision::with('section')->latest()->limit(100)->get(),
            'teamName' => SystemSetting::getValue('docs_team_name', 'Team TriProof AI'),
            'visibilityEnabled' => SystemSetting::getValue('docs_visibility_enabled', '1') === '1',
            'scheduleEnabled' => SystemSetting::getValue('docs_schedule_enabled', '0') === '1',
            'availableFrom' => SystemSetting::getValue('docs_available_from'),
            'availableUntil' => SystemSetting::getValue('docs_available_until'),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'docs_visibility_enabled' => ['required', 'in:0,1'],
            'docs_schedule_enabled' => ['required', 'in:0,1'],
            'docs_available_from' => ['nullable', 'date'],
            'docs_available_until' => ['nullable', 'date', 'after_or_equal:docs_available_from'],
            'docs_team_name' => ['nullable', 'string', 'max:120'],
        ]);

        SystemSetting::setMany([
            'docs_visibility_enabled' => $data['docs_visibility_enabled'],
            'docs_schedule_enabled' => $data['docs_schedule_enabled'],
            'docs_available_from' => $data['docs_available_from'] ?? null,
            'docs_available_until' => $data['docs_available_until'] ?? null,
            'docs_team_name' => $data['docs_team_name'] ?? 'Team TriProof AI',
        ]);

        Cache::forget('docs_live_telemetry');

        return back()->with('status', 'Documentation visibility and schedule updated.');
    }

    public function updateSection(Request $request, DocSection $section)
    {
        $data = $request->validate([
            'title_en' => ['required', 'string', 'max:255'],
            'title_bn' => ['nullable', 'string', 'max:255'],
            'subtitle_en' => ['nullable', 'string', 'max:255'],
            'subtitle_bn' => ['nullable', 'string', 'max:255'],
            'category' => ['required', 'in:pitch_deck,technical,operations'],
            'content_markdown' => ['nullable', 'string'],
            'draft_markdown' => ['nullable', 'string'],
            'diagram_mermaid' => ['nullable', 'string'],
            'is_visible' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:published,draft'],
            'action' => ['nullable', 'in:save_draft,publish'],
        ]);

        $publish = ($data['action'] ?? '') === 'publish' || $request->boolean('publish');

        $section->fill([
            'title_en' => $data['title_en'],
            'title_bn' => $data['title_bn'] ?? null,
            'subtitle_en' => $data['subtitle_en'] ?? null,
            'subtitle_bn' => $data['subtitle_bn'] ?? null,
            'category' => $data['category'],
            'content_markdown' => $data['content_markdown'] ?? $section->content_markdown,
            'draft_markdown' => $data['draft_markdown'] ?? null,
            'diagram_mermaid' => $data['diagram_mermaid'] ?? null,
            'is_visible' => $request->boolean('is_visible'),
            'status' => $data['status'] ?? $section->status,
        ]);
        $section->updated_by = $request->user()?->id;

        if ($publish) {
            if (! empty($data['draft_markdown'])) {
                $section->content_markdown = $data['draft_markdown'];
            }
            $section->status = 'published';
        }

        $section->save();

        if ($publish) {
            $this->recordRevision($section, $request->input('change_note') ?: 'Published via documentation studio.');
        }

        Cache::forget('docs_live_telemetry');

        return back()->with('status', $publish
            ? "Section \"{$section->title_en}\" published and a revision was created."
            : "Draft for \"{$section->title_en}\" saved.");
    }

    public function publishSection(Request $request, DocSection $section)
    {
        if (! empty($section->draft_markdown)) {
            $section->content_markdown = $section->draft_markdown;
        }
        $section->status = 'published';
        $section->updated_by = $request->user()?->id;
        $section->save();

        $this->recordRevision($section, $request->input('change_note') ?: 'Published via documentation studio.');

        return back()->with('status', "Section \"{$section->title_en}\" published.");
    }

    public function reorderSections(Request $request)
    {
        $order = $request->input('order', $request->input('sections', []));

        if (! is_array($order)) {
            $order = [];
        }

        DB::transaction(function () use ($order): void {
            foreach (array_values($order) as $index => $id) {
                DocSection::whereKey($id)->update(['sort_order' => ($index + 1) * 10]);
            }
        });

        return response()->json(['status' => 'ok']);
    }

    public function restoreRevision(Request $request, DocRevision $revision)
    {
        $snapshot = $revision->snapshot();

        DB::transaction(function () use ($snapshot): void {
            foreach (($snapshot['sections'] ?? []) as $row) {
                if (! is_array($row) || empty($row['slug'])) {
                    continue;
                }

                DocSection::updateOrCreate(
                    ['slug' => $row['slug']],
                    Arr::only($row, self::SECTION_FIELDS)
                );
            }

            $settings = is_array($snapshot['settings'] ?? null) ? $snapshot['settings'] : [];
            foreach (self::DOCS_SETTING_KEYS as $key) {
                if (array_key_exists($key, $settings)) {
                    SystemSetting::setValue($key, $settings[$key]);
                }
            }

            foreach (($snapshot['team'] ?? []) as $member) {
                if (! is_array($member) || empty($member['email'])) {
                    continue;
                }

                DocTeamMember::updateOrCreate(
                    ['email' => $member['email']],
                    Arr::only($member, ['full_name', 'role_title', 'email', 'bio_short', 'photo_path', 'sort_order', 'is_active'])
                );
            }
        });

        Cache::forget('docs_live_telemetry');

        return back()->with('status', "Revision {$revision->version_label} restored.");
    }

    public function storeTeamMember(Request $request)
    {
        $data = $this->validateTeamMember($request);

        $photoPath = $request->hasFile('photo')
            ? $this->storeTeamPhoto($request)
            : null;

        DocTeamMember::create([
            'full_name' => $data['full_name'],
            'role_title' => $data['role_title'],
            'email' => $data['email'],
            'bio_short' => $data['bio_short'] ?? null,
            'photo_path' => $photoPath,
            'sort_order' => $data['sort_order'] ?? ((int) DocTeamMember::max('sort_order') + 1),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('status', 'Team member added.');
    }

    public function updateTeamMember(Request $request, DocTeamMember $member)
    {
        $data = $this->validateTeamMember($request);

        $photoPath = $member->photo_path;
        if ($request->hasFile('photo')) {
            $this->deleteTeamPhoto($member->photo_path);
            $photoPath = $this->storeTeamPhoto($request);
        }

        $member->update([
            'full_name' => $data['full_name'],
            'role_title' => $data['role_title'],
            'email' => $data['email'],
            'bio_short' => $data['bio_short'] ?? null,
            'photo_path' => $photoPath,
            'sort_order' => $data['sort_order'] ?? $member->sort_order,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('status', 'Team member updated.');
    }

    public function destroyTeamMember(DocTeamMember $member)
    {
        $this->deleteTeamPhoto($member->photo_path);
        $member->delete();

        return back()->with('status', 'Team member removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTeamMember(Request $request): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'role_title' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'bio_short' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function recordRevision(DocSection $section, string $note): DocRevision
    {
        $next = (int) DocRevision::max('id') + 1;

        $snapshot = [
            'sections' => DocSection::orderBy('sort_order')->get()->toArray(),
            'settings' => SystemSetting::allAsArray(),
            'team' => DocTeamMember::orderBy('sort_order')->get()->toArray(),
        ];

        return DocRevision::create([
            'doc_section_id' => $section->id,
            'version_label' => 'v2.1.0-r'.$next,
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'change_note' => Str::limit($note, 250),
            'created_by' => Auth::id(),
        ]);
    }

    private function storeTeamPhoto(Request $request): string
    {
        $file = $request->file('photo');
        $directory = public_path('uploads/team');

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages(['photo' => 'Unable to create the team upload directory.']);
        }

        $contents = @file_get_contents($file->getRealPath());
        $source = $contents !== false ? @imagecreatefromstring($contents) : false;

        if ($source === false) {
            throw ValidationException::withMessages(['photo' => 'The uploaded image could not be processed.']);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $sourceX = (int) (($width - $side) / 2);
        $sourceY = (int) (($height - $side) / 2);

        $target = imagecreatetruecolor(256, 256);
        $background = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $background);
        imagecopyresampled($target, $source, 0, 0, $sourceX, $sourceY, 256, 256, $side, $side);

        $filename = Str::uuid()->toString().'.jpg';
        imagejpeg($target, $directory.'/'.$filename, 90);

        imagedestroy($source);
        imagedestroy($target);

        return $filename;
    }

    private function deleteTeamPhoto(?string $photoPath): void
    {
        if (! $photoPath) {
            return;
        }

        $path = public_path('uploads/team/'.$photoPath);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
