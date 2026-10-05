<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\Paymob;
use App\trait\image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymobController extends Controller
{
    use image;
 
    public function view(): JsonResponse
    {
        $paymob = Paymob::first();

        return response()->json([
            'status' => true,
            'data' => $paymob,
        ]);
    } 
 
    public function update(Request $request): JsonResponse
    {
        $paymob = Paymob::first();

        $rules = [
            'title' => $paymob ? 'sometimes|required|string|max:255' : 'required|string|max:255',
            'type' => $paymob ? 'sometimes|required|in:live,test' : 'required|in:live,test',
            'callback' => $paymob ? 'sometimes|required|string|max:1000' : 'required|string|max:1000',
            'api_key' => $paymob ? 'sometimes|required|string|max:1000' : 'required|string|max:1000',
            'iframe_id' => $paymob ? 'sometimes|required|string|max:255' : 'required|string|max:255',
            'integration_id' => $paymob ? 'sometimes|required|string|max:255' : 'required|string|max:255',
            'Hmac' => $paymob ? 'sometimes|required|string|max:255' : 'required|string|max:255',
            'logo' => $paymob
                ? 'sometimes|nullable|image|mimes:jpeg,png,jpg,gif,svg,webp,avif|max:5120'
                : 'required|image|mimes:jpeg,png,jpg,gif,svg,webp,avif|max:5120',
        ];

        $validated = $request->validate($rules);
   
        if ($paymob) {
            // If new logo uploaded, replace old logo file via update_image
            if ($request->hasFile('logo')) {
                $oldLogoPath = $paymob->getRawOriginal('logo');
                $uploadedPath = $this->update_image($request, $oldLogoPath, 'logo', 'paymob');
                if ($uploadedPath) {
                    $validated['logo'] = $uploadedPath;
                }
            } else {
                unset($validated['logo']);
            }

            $paymob->update($validated);
            $paymob->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Paymob settings updated successfully.',
                'data' => $paymob,
            ]);
        }

        // If Paymob settings are empty, create new record via upload
        if ($request->hasFile('logo')) {
            $uploadedPath = $this->upload($request, 'logo', 'paymob');
            if ($uploadedPath) {
                $validated['logo'] = $uploadedPath;
            }
        }

        $paymob = Paymob::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Paymob settings created successfully.',
            'data' => $paymob,
        ], Response::HTTP_CREATED);
    } 
}
