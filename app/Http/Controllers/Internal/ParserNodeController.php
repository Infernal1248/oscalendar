<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParserNodeController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'node_id' => ['required', 'string', 'max:100', 'regex:/\A[a-zA-Z0-9_.-]+\z/'],
            'source' => ['required', 'string', 'max:64'],
            'version' => ['required', 'string', 'max:100'],
            'max_workers' => ['required', 'integer', 'between:1,1000'],
            'busy_workers' => ['required', 'integer', 'min:0', 'lte:max_workers'],
        ]);
        DB::table('parser_nodes')->upsert(
            [array_merge($data, ['last_seen_at' => now()])],
            ['node_id'], ['source', 'version', 'max_workers', 'busy_workers', 'last_seen_at']
        );

        return response()->json(['ok' => true]);
    }
}
