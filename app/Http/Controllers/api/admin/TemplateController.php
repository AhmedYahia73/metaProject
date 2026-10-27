<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTemplateRequest;
use App\Http\Requests\Admin\UpdateTemplateRequest;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TemplateController extends Controller
{
    /**
     * Display a listing of templates.
     */
    public function index(Request $request): JsonResponse
    {
        $templates = $request->boolean('paginate')
            ? Template::latest()->paginate($request->integer('per_page', 15))
            : Template::latest()->get();

        return response()->json([
            'status' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Store a newly created template in storage.
     */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        $template = Template::create([
            'request_template' => [
                'groups' => $request->validated('groups'),
            ],
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Template created successfully.',
            'data' => $template,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified template.
     */
    public function show(Template $template): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $template,
        ]);
    }

    /**
     * Update the specified template in storage.
     */
    public function update(UpdateTemplateRequest $request, Template $template): JsonResponse
    {
        if ($request->has('groups')) {
            $template->update([
                'request_template' => [
                    'groups' => $request->validated('groups'),
                ],
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Template updated successfully.',
            'data' => $template,
        ]);
    }

    /**
     * Remove the specified template from storage.
     */
    public function destroy(Template $template): JsonResponse
    {
        $template->delete();

        return response()->json([
            'status' => true,
            'message' => 'Template deleted successfully.',
        ]);
    }
}
