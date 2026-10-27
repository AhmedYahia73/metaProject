<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateAiFileRequest;
use App\Http\Requests\Admin\StoreTemplateRequest;
use App\Http\Requests\Admin\UpdateTemplateRequest;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\Template;
use App\Models\TikTokAccount;
use App\Models\WhatsItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
     * Display dropdown lists for all account types showing only id and identifying column.
     */
    public function accounts(Request $request): JsonResponse
    {
        $allAccounts = [
            'whats_items' => WhatsItem::select('id', 'phone')->latest()->get(),
            'instgram_items' => InstagramItem::select('id', 'username')->latest()->get(),
            'messenger_accounts' => MessengerAccount::select('id', 'page_name')->latest()->get(),
            'telegram_accounts' => TelegramAccount::select('id', 'bot_username')->latest()->get(),
            'tiktok_accounts' => TikTokAccount::select('id', 'username')->latest()->get(),
        ];

        if ($request->filled('type')) {
            $type = (string) $request->input('type');
            $map = [
                'whats_item' => 'whats_items',
                'whats_items' => 'whats_items',
                'instgram_item' => 'instgram_items',
                'instagram_item' => 'instgram_items',
                'instgram_items' => 'instgram_items',
                'instagram_items' => 'instgram_items',
                'messenger_account' => 'messenger_accounts',
                'messenger_accounts' => 'messenger_accounts',
                'telegram_account' => 'telegram_accounts',
                'telegram_accounts' => 'telegram_accounts',
                'tiktok_account' => 'tiktok_accounts',
                'tiktok_accounts' => 'tiktok_accounts',
            ];

            if (isset($map[$type])) {
                $key = $map[$type];

                return response()->json([
                    'status' => true,
                    'data' => [
                        $key => $allAccounts[$key],
                    ],
                ]);
            }
        }

        return response()->json([
            'status' => true,
            'data' => $allAccounts,
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

    public function generateAiFile(GenerateAiFileRequest $request): JsonResponse
    {
        [$targetModel, $accountType] = $this->resolveTargetAccount($request);

        $markdown = $this->convertGroupsToMarkdown($request->validated('groups'));

        $filename = 'ai_files/'.$accountType.'_'.$targetModel->id.'_'.uniqid().'.md';

        if ($targetModel->ai_file && Storage::disk('public')->exists($targetModel->ai_file)) {
            Storage::disk('public')->delete($targetModel->ai_file);
        }

        Storage::disk('public')->put($filename, $markdown);

        $targetModel->update([
            'ai_file' => $filename,
        ]);

        $fullUrl = url(Storage::url($filename));

        return response()->json([
            'status' => true,
            'message' => 'Markdown file generated and saved successfully.',
            'data' => [
                'account_type' => $accountType,
                'account_id' => $targetModel->id,
                'ai_file' => $filename,
                'file_url' => $fullUrl,
                'markdown' => $markdown,
            ],
        ]);
    }

    /**
     * Resolve the target account model based on the request.
     *
     * @return array{0: Model, 1: string}
     */
    protected function resolveTargetAccount(GenerateAiFileRequest $request): array
    {
        if ($request->filled('whats_item_id')) {
            return [WhatsItem::findOrFail($request->input('whats_item_id')), 'whats_item'];
        }

        if ($request->filled('instgram_item_id') || $request->filled('instagram_item_id')) {
            $id = $request->input('instgram_item_id') ?? $request->input('instagram_item_id');

            return [InstagramItem::findOrFail($id), 'instgram_item'];
        }

        if ($request->filled('messenger_account_id')) {
            return [MessengerAccount::findOrFail($request->input('messenger_account_id')), 'messenger_account'];
        }

        if ($request->filled('telegram_account_id')) {
            return [TelegramAccount::findOrFail($request->input('telegram_account_id')), 'telegram_account'];
        }

        if ($request->filled('tiktok_account_id')) {
            return [TikTokAccount::findOrFail($request->input('tiktok_account_id')), 'tiktok_account'];
        }

        abort(422, 'No valid account ID provided.');
    }

    /**
     * Convert groups structure into clean GitHub Flavored Markdown.
     *
     * @param  array<int, array<string, mixed>>  $groups
     */
    protected function convertGroupsToMarkdown(array $groups): string
    {
        $sections = [];

        foreach ($groups as $group) {
            $name = trim((string) ($group['name'] ?? ''));
            $data = $group['data'] ?? [];

            $sectionLines = [];

            if ($name !== '') {
                $sectionLines[] = "# {$name}";
                $sectionLines[] = '';
            }

            if (is_array($data) && ! empty($data)) {
                $firstRow = $data[0] ?? [];
                if (is_array($firstRow) && ! empty($firstRow)) {
                    $headers = [];
                    foreach ($firstRow as $field) {
                        $headers[] = $this->sanitizeMarkdownCell($field['lable'] ?? '');
                    }

                    if (! empty($headers)) {
                        $sectionLines[] = '| '.implode(' | ', $headers).' |';
                        $sectionLines[] = '| '.implode(' | ', array_fill(0, count($headers), '---')).' |';

                        foreach ($data as $row) {
                            if (! is_array($row)) {
                                continue;
                            }

                            $cells = [];
                            foreach ($row as $field) {
                                $cells[] = $this->sanitizeMarkdownCell($field['value'] ?? '');
                            }

                            if (count($cells) < count($headers)) {
                                $cells = array_pad($cells, count($headers), '');
                            }

                            $sectionLines[] = '| '.implode(' | ', $cells).' |';
                        }
                    }
                }
            }

            $sections[] = implode("\n", $sectionLines);
        }

        return implode("\n\n", $sections)."\n";
    }

    /**
     * Escape and sanitize cell content for Markdown table row.
     */
    protected function sanitizeMarkdownCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } else {
            $value = (string) $value;
        }

        $value = str_replace('|', '\|', $value);
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);

        return trim($value);
    }
}
