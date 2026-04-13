<?php

namespace App\Http\Controllers;

class RemarketingController extends Controller
{
    public function index()
    {
        $stages = ['all', 'fresh', 'cooling', 'cold', 'dormant'];

        return view('remarketing.index', [
            'stages' => $stages,
        ]);
    }
}
