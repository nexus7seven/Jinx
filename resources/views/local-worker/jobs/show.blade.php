@extends('layouts.app')

@section('title', 'Local Worker Job #'.$job->id)

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h1 style="margin:0; font-size:20px;">Local Worker Job #{{ $job->id }}</h1>
        <a href="{{ route('local-worker.jobs.index') }}" style="color:#93c5fd;">Back to jobs</a>
    </div>

    <div style="padding:12px; border:1px solid rgba(51, 65, 85, 0.55); border-radius:10px; margin-bottom:14px;">
        <div><strong>Lead ID:</strong> {{ $job->lead_id ?? '-' }}</div>
        <div><strong>Job Type:</strong> {{ $job->job_type }}</div>
        <div><strong>Status:</strong> {{ $job->status }}</div>
        <div><strong>Claimed By:</strong> {{ $job->claimed_by ?? '-' }}</div>
        <div><strong>Claimed At:</strong> {{ $job->claimed_at ?? '-' }}</div>
        <div><strong>Heartbeat At:</strong> {{ $job->heartbeat_at ?? '-' }}</div>
        <div><strong>Started At:</strong> {{ $job->started_at ?? '-' }}</div>
        <div><strong>Finished At:</strong> {{ $job->finished_at ?? '-' }}</div>
        <div><strong>Created At:</strong> {{ $job->created_at }}</div>
        <div><strong>Updated At:</strong> {{ $job->updated_at }}</div>
    </div>

    <h2 style="font-size:16px; margin:0 0 8px;">Payload JSON</h2>
    <pre style="padding:12px; border:1px solid rgba(51, 65, 85, 0.55); border-radius:10px; overflow:auto;">{{ json_encode($job->payload_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>

    <h2 style="font-size:16px; margin:14px 0 8px;">Result JSON</h2>
    <pre style="padding:12px; border:1px solid rgba(51, 65, 85, 0.55); border-radius:10px; overflow:auto;">{{ json_encode($job->result_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>

    <h2 style="font-size:16px; margin:14px 0 8px;">Error Text</h2>
    <pre style="padding:12px; border:1px solid rgba(51, 65, 85, 0.55); border-radius:10px; overflow:auto;">{{ $job->error_text ?: '-' }}</pre>

    <h2 style="font-size:16px; margin:14px 0 8px;">Artifacts</h2>
    <div style="padding:12px; border:1px solid rgba(51, 65, 85, 0.55); border-radius:10px;">
        @forelse ($job->artifacts as $artifact)
            <div style="padding:8px 0; border-bottom:1px solid rgba(51, 65, 85, 0.35);">
                <div><strong>#{{ $artifact->id }}</strong> ({{ $artifact->type }})</div>
                <div>Path: <code>{{ $artifact->path }}</code></div>
                <div>Original name: {{ $artifact->original_name ?? '-' }}</div>
                <div>MIME: {{ $artifact->mime_type ?? '-' }} | Size: {{ $artifact->size_bytes ?? '-' }}</div>
                <div>Meta: <code>{{ json_encode($artifact->meta_json, JSON_UNESCAPED_SLASHES) }}</code></div>
                <div>
                    <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($artifact->path) }}" target="_blank" style="color:#93c5fd;">
                        Open artifact URL
                    </a>
                </div>
            </div>
        @empty
            <div>No artifacts.</div>
        @endforelse
    </div>
@endsection
