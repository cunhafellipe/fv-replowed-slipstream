<?php

namespace App\Http\Controllers;

use App\Support\SuperCrops;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperCropCertificationController extends Controller
{
    public function inbox(Request $request): JsonResponse
    {
        return response()->json(['requests' => SuperCrops::inbox($request->user()->uid)]);
    }

    public function respond(Request $request): JsonResponse
    {
        $input = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'accept' => ['required', 'boolean'],
        ]);

        $success = SuperCrops::respond($request->user()->uid, (int) $input['id'], (bool) $input['accept']);
        return response()->json(['success' => $success], $success ? 200 : 409);
    }
}
