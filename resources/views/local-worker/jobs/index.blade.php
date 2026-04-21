@extends('layouts.app')

@section('title', 'Local Worker Jobs')

@section('content')
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h1 style="margin:0; font-size:20px;">Local Worker Jobs</h1>
    </div>

    <div style="overflow:auto; border:1px solid rgba(51, 65, 85, 0.55); border-radius:10px;">
        <table style="width:100%; border-collapse:collapse; min-width:760px;">
            <thead style="background:rgba(15, 23, 42, 0.75);">
                <tr>
                    <th style="text-align:left; padding:10px;">ID</th>
                    <th style="text-align:left; padding:10px;">Job Type</th>
                    <th style="text-align:left; padding:10px;">Status</th>
                    <th style="text-align:left; padding:10px;">Claimed By</th>
                    <th style="text-align:left; padding:10px;">Created</th>
                    <th style="text-align:left; padding:10px;">Updated</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($jobs as $job)
                <tr style="border-top:1px solid rgba(51, 65, 85, 0.35);">
                    <td style="padding:10px;">
                        <a href="{{ route('local-worker.jobs.show', $job) }}" style="color:#93c5fd;">{{ $job->id }}</a>
                    </td>
                    <td style="padding:10px;">{{ $job->job_type }}</td>
                    <td style="padding:10px;">{{ $job->status }}</td>
                    <td style="padding:10px;">{{ $job->claimed_by ?? '-' }}</td>
                    <td style="padding:10px;">{{ $job->created_at }}</td>
                    <td style="padding:10px;">{{ $job->updated_at }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="padding:12px;">No local worker jobs yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:14px;">
        {{ $jobs->links() }}
    </div>
@endsection
