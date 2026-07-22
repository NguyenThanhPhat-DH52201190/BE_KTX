<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriorityCriteria;

class PriorityCriteriaController extends Controller
{
    public function index()
    {
        $criteria = PriorityCriteria::where('is_active', true)
            ->orderBy('tier')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'description', 'priority_score', 'tier']);

        return response()->json($criteria);
    }
}
