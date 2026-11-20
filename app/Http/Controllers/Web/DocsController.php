<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DocSection;
use App\Models\DocTeamMember;
use App\Models\SystemSetting;
use App\Services\DocsTelemetryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFacade;

class DocsController extends Controller
{
    public function __construct(
        protected DocsTelemetryService $telemetry
    ) {}

    public function index(Request $request)
    {
        return view('docs.index', [
            'sections' => DocSection::where('is_visible', true)->orderBy('sort_order')->get(),
            'team' => DocTeamMember::where('is_active', true)->orderBy('sort_order')->get(),
            'telemetry' => $this->telemetry->getTelemetry(),
            'teamName' => SystemSetting::getValue('docs_team_name', 'Team TriProof AI'),
            'previewActive' => $request->attributes->get('docs_preview_active', false),
        ]);
    }

    public function exportPdf()
    {
        $data = [
            'sections' => DocSection::where('is_visible', true)->orderBy('sort_order')->get(),
            'team' => DocTeamMember::where('is_active', true)->orderBy('sort_order')->get(),
            'telemetry' => $this->telemetry->getTelemetry(),
            'teamName' => SystemSetting::getValue('docs_team_name', 'Team TriProof AI'),
        ];

        $pdf = Pdf::loadView('docs.exports.pdf', $data);

        return $pdf->download('TriProof-AI-Documentation.pdf');
    }

    public function exportMarkdown()
    {
        $sections = DocSection::where('is_visible', true)->orderBy('sort_order')->get();

        $content = "# TriProof AI Documentation\n\n";
        foreach ($sections as $section) {
            $content .= "## {$section->title_en}\n\n{$section->content_markdown}\n\n---\n\n";
        }

        return ResponseFacade::streamDownload(function () use ($content) {
            echo $content;
        }, 'TriProof-AI-Documentation.md', [
            'Content-Type' => 'text/markdown',
        ]);
    }
}
