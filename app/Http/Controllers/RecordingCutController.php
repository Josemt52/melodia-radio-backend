<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AudioCutService;
use Illuminate\Support\Facades\Response;

class RecordingCutController extends Controller
{
    public function __construct(
        private AudioCutService $service
    ) {}

    public function cut(Request $request)
    {
        $request->validate([
            "date" => "required|date",
            "start" => "required",
            "end" => "required"
        ]);

        $file = $this->service->cut(
            $request->date,
            $request->start,
            $request->end
        );

        return response()->download($file);
    }
}
