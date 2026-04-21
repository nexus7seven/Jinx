<?php

namespace App\Http\Controllers;

use App\Models\LocalBrowserJob;

class LocalWorkerJobsPageController extends Controller
{
    public function index()
    {
        $jobs = LocalBrowserJob::query()
            ->orderByDesc('id')
            ->paginate(50);

        return view('local-worker.jobs.index', compact('jobs'));
    }

    public function show(LocalBrowserJob $job)
    {
        $job->load('artifacts');

        return view('local-worker.jobs.show', compact('job'));
    }
}
