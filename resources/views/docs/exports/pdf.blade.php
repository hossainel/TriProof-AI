<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        body { font-family: sans-serif; font-size: 14px; line-height: 1.6; color: #333; }
        h1 { font-size: 28px; color: #000; }
        h2 { font-size: 22px; margin-top: 20px; color: #111; }
        .prose { margin-top: 10px; }
    </style>
</head>
<body>
    <h1>TriProof AI Documentation</h1>
    @foreach($sections as $section)
        <h2>{{ $section->displayTitle() }}</h2>
        <div class="prose">
            {!! \Illuminate\Support\Str::markdown($section->liveMarkdown()) !!}
        </div>
    @endforeach
</body>
</html>
