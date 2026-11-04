<?php

namespace App\trait;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait FormatsChatLinks
{
    /**
     * Normalize URL to ensure it has a valid http/https scheme and is clean.
     */
    public function normalizeChatUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $trimmed = trim($url);
        // Strip any surrounding brackets, quotes, or angle brackets
        $trimmed = trim($trimmed, "<>\"'[]() \t\n\r\0\x0B");

        if (empty($trimmed)) {
            return null;
        }

        if (! preg_match('~^https?://~i', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        return $trimmed;
    }

    /**
     * Format a polite ordering message with direct, clickable URLs.
     */
    public function formatOrderingLinksMessage(?string $websiteUrl, ?string $androidLink, ?string $iosLink): ?string
    {
        $websiteUrl = $this->normalizeChatUrl($websiteUrl);
        $androidLink = $this->normalizeChatUrl($androidLink);
        $iosLink = $this->normalizeChatUrl($iosLink);

        if (! $websiteUrl && ! $androidLink && ! $iosLink) {
            return null;
        }

        $lines = ['تقدر تطلب من هنا:'];

        if ($websiteUrl) {
            $lines[] = "الموقع الإلكتروني:\n{$websiteUrl}";
        }
        if ($androidLink) {
            $lines[] = "تطبيق أندرويد (Android):\n{$androidLink}";
        }
        if ($iosLink) {
            $lines[] = "تطبيق آيفون (iOS):\n{$iosLink}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Format the links section to pass into AI prompt instructions.
     */
    public function buildLinksPromptSection(?string $websiteUrl, ?string $androidLink, ?string $iosLink): string
    {
        $websiteUrl = $this->normalizeChatUrl($websiteUrl);
        $androidLink = $this->normalizeChatUrl($androidLink);
        $iosLink = $this->normalizeChatUrl($iosLink);

        if (! $websiteUrl && ! $androidLink && ! $iosLink) {
            return '';
        }

        $section = "\n\nروابط وتفاصيل الطلب المتاحة:";
        if ($websiteUrl) {
            $section .= "\n- رابط الموقع الإلكتروني المباشر: {$websiteUrl}";
        }
        if ($androidLink) {
            $section .= "\n- رابط تطبيق أندرويد (Android) المباشر: {$androidLink}";
        }
        if ($iosLink) {
            $section .= "\n- رابط تطبيق آيفون (iOS) المباشر: {$iosLink}";
        }

        return $section;
    }

    /**
     * Strict link formatting instructions to ensure the AI NEVER writes brackets [] or markdown links.
     */
    public function getLinkPromptInstructions(): string
    {
        return <<<'INSTRUCTIONS'
- قواعد هامة جداً لكتابة الروابط (لتظهر روابط زرقاء تفاعلية قابلة للنقر):
  * ممنوع منعاً باتاً استخدام أقواس الماركداون مثل [نص](رابط) أو وضع أي أقواس مربعة [] أو دائرية () حول الروابط لأن تطبيقات المراسلة (واتساب، تيليجرام، ماسنجر، إنستجرام، تيك توك) لا تدعمها وتظهر للمستخدم كنصوص عادية غير قابلة للفتح والنقر.
  * اكتب الرابط صريحاً وكاملاً ومباشراً كما هو موضح أعلاه يبدأ بـ https:// مفصولاً بسطر مستقل أو مسافة قبله وبعده، مثل:
    الموقع الإلكتروني:
    https://example.com
INSTRUCTIONS;
    }

    /**
     * Post-processing sanitizer: cleans any stray markdown brackets `[Text](URL)` or `[URL]` into direct clickable URLs.
     */
    public function cleanChatLinks(string $text): string
    {
        if (empty($text)) {
            return $text;
        }

        // 1. Convert markdown link [Text](https://url) => Text: \n https://url
        $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/u', function ($matches) {
            $label = trim($matches[1]);
            $url = trim($matches[2]);

            return "{$label}:\n{$url}";
        }, $text);

        // 2. Convert markdown link without label or [https://url] => https://url
        $text = preg_replace('/\[(https?:\/\/[^\]]+)\]/u', '$1', $text);

        // 3. Remove parentheses tightly wrapping a URL: (https://...) => https://...
        $text = preg_replace('/(?<=\s|^)\((https?:\/\/[^\s\)]+)\)(?=\s|$|[.,!?])/u', '$1', $text);

        // 4. Ensure there is a newline or space before https:// if glued to Arabic letters or punctuation
        $text = preg_replace('/([^\s\n:\/])(https?:\/\/)/u', "$1\n$2", $text);

        return trim($text);
    }

    /**
     * Resolve the full /api/public/products endpoint URL from an api_link.
     */
    public function resolveProductsApiUrl(object|string|null $itemOrLink): ?string
    {
        $apiLink = is_object($itemOrLink) ? ($itemOrLink->api_link ?? null) : $itemOrLink;

        if (empty($apiLink) || ! is_string($apiLink)) {
            return null;
        }

        $trimmed = trim($apiLink);
        // Strip leading "api " prefix or colons if user typed e.g. "api https://..."
        $trimmed = preg_replace('/^api[:\s]+/i', '', $trimmed);
        $trimmed = trim($trimmed, "<>\"' \t\n\r\0\x0B");

        if (empty($trimmed)) {
            return null;
        }

        // Ensure proper scheme
        if (! preg_match('~^https?://~i', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        // Check if the URL already has the /api/public/products path
        $parsed = parse_url($trimmed);
        $path = rtrim($parsed['path'] ?? '', '/');

        if (str_ends_with($path, '/api/public/products') || str_ends_with($path, '/public/products')) {
            return $trimmed;
        } elseif (str_ends_with($path, '/api')) {
            return rtrim($trimmed, '/').'/public/products';
        }

        return rtrim($trimmed, '/').'/api/public/products';
    }

    /**
     * Fetch public products from the configured api_link.
     * Appends /api/public/products to the api_link if not already present.
     *
     * @param  object|string|null  $itemOrLink  Model with api_link attribute or raw URL string
     * @return string|null Formatted string containing products for AI, or null
     */
    public function fetchProductsFromApiLink(object|string|null $itemOrLink): ?string
    {
        $url = $this->resolveProductsApiUrl($itemOrLink);
        if (empty($url)) {
            return null;
        }

        $cacheKey = 'products_api_data_'.md5($url);

        try {
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
        } catch (\Throwable) {
            // Ignore cache read failures and proceed with HTTP request
        }

        try {
            $response = Http::timeout(8)
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ],
                ])
                ->retry(2, 200, throw: false)
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();
                $formattedCatalog = is_array($data) ? $this->formatProductsCatalog($data) : null;
                $rawJson = is_array($data)
                    ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    : trim((string) $response->body());

                $content = ! empty($formattedCatalog)
                    ? "{$formattedCatalog}\n\nبيانات تفصيلية إضافية (JSON):\n{$rawJson}"
                    : $rawJson;

                if (! empty($content)) {
                    try {
                        Cache::put($cacheKey, $content, 60);
                    } catch (\Throwable) {
                        // Ignore cache write failures
                    }

                    return $content;
                }
            } else {
                Log::warning("Products API returned non-200 status [{$response->status()}] for URL [{$url}]");
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch products from api_link [{$url}]: ".$e->getMessage());
        }

        return null;
    }

    /**
     * Format raw products API data into a human-readable Arabic list for AI.
     */
    public function formatProductsCatalog(array $data): string
    {
        $rawItems = $data['data']['data'] ?? $data['data'] ?? $data['products'] ?? (array_is_list($data) ? $data : []);

        if (! is_array($rawItems) || empty($rawItems)) {
            // Check if $data is an associative array of arrays (e.g. ['endpoint_1' => [...], 'endpoint_2' => [...]])
            if (! array_is_list($data) && ! empty($data)) {
                $sections = [];
                foreach ($data as $endpointName => $items) {
                    if (is_array($items) && ! empty($items)) {
                        $itemsList = array_is_list($items) ? $items : array_values($items);
                        $formattedSection = $this->formatItemsList($itemsList);
                        if (! empty($formattedSection)) {
                            $sections[] = "### قسم [{$endpointName}]:\n".$formattedSection;
                        }
                    }
                }
                if (! empty($sections)) {
                    return "بيانات وأقسام المنتجات والخدمات المتاحة:\n".implode("\n\n", $sections);
                }
            }

            return '';
        }

        $formatted = $this->formatItemsList($rawItems);

        return ! empty($formatted) ? "قائمة المنتجات والأسعار المتاحة:\n".$formatted : '';
    }

    /**
     * Format a flat list of items into readable lines.
     *
     * @param  array<int, mixed>  $rawItems
     */
    protected function formatItemsList(array $rawItems): string
    {
        $lines = [];

        foreach ($rawItems as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $item['ar_name'] ?? $item['name'] ?? $item['title'] ?? ('منتج '.($index + 1));
            $desc = $item['ar_description'] ?? $item['description'] ?? $item['desc'] ?? null;
            $price = $item['price'] ?? null;

            $line = "- اسم المنتج: {$name}";

            if ($price !== null && $price !== '') {
                $formattedPrice = is_numeric($price) ? number_format((float) $price, 0, '.', ',') : $price;
                $line .= " | السعر: {$formattedPrice}";
            }

            if (! empty($desc)) {
                $line .= " | الوصف: {$desc}";
            }

            if (! empty($item['options']) && is_array($item['options'])) {
                $optionsList = [];
                foreach ($item['options'] as $opt) {
                    if (is_array($opt)) {
                        $optName = $opt['option_name'] ?? $opt['name'] ?? '';
                        $optPrice = $opt['price'] ?? null;
                        if ($optPrice !== null) {
                            $formattedOptPrice = is_numeric($optPrice) ? number_format((float) $optPrice, 0, '.', ',') : $optPrice;
                            $optionsList[] = "{$optName} (بسعر {$formattedOptPrice})";
                        } elseif ($optName) {
                            $optionsList[] = $optName;
                        }
                    }
                }
                if (! empty($optionsList)) {
                    $line .= ' | الخيارات/الألوان المتاحة: '.implode('، ', $optionsList);
                }
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Build the API products section for the AI prompt.
     * Prefers cached user_data from DB to eliminate HTTP latency inside webhooks.
     */
    public function buildApiProductsPromptSection(object|string|null $itemOrLink): string
    {
        $products = null;

        // 1. Prefer user_data cached from DB (zero HTTP overhead during webhook processing)
        if (is_object($itemOrLink) && ! empty($itemOrLink->user_data) && is_array($itemOrLink->user_data)) {
            $formattedCatalog = $this->formatProductsCatalog($itemOrLink->user_data);
            $rawJson = json_encode($itemOrLink->user_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $products = ! empty($formattedCatalog)
                ? "{$formattedCatalog}\n\nبيانات تفصيلية إضافية (JSON):\n{$rawJson}"
                : $rawJson;

            Log::info('[ProductsCatalog] Loaded products from cached user_data for webhook prompt.', [
                'channel' => class_basename($itemOrLink),
                'account_id' => $itemOrLink->id ?? null,
            ]);
        }

        // 2. Fallback to direct HTTP fetch if user_data is empty but api_link is present
        if (empty($products)) {
            Log::info('[ProductsCatalog] user_data is empty; attempting on-the-fly fetch from api_link.', [
                'channel' => is_object($itemOrLink) ? class_basename($itemOrLink) : null,
                'account_id' => $itemOrLink->id ?? null,
            ]);
            $products = $this->fetchProductsFromApiLink($itemOrLink);
        }

        if (empty($products)) {
            return '';
        }

        return <<<SECTION


بيانات وقائمة المنتجات والخدمات المتاحة حالياً للطلب والبيع:
{$products}

تعليمات صارمة بخصوص المنتجات أعلاه:
- المنتجات المذكورة أعلاه هي المنتجات والخدمات المتوفرة والمعتمدة للطلب والبيع من خلال هذا الحساب.
- إذا سأل العميل عن المنتجات المتوفرة، الأصناف، الأسعار، أو تفاصيل أي منتج، يجب أن تعرض له المنتجات المذكورة أعلاه بأسعارها وتفاصيلها وخياراتها المتاحة فوراً وبأسلوب مهذب ومساعد.
- ممنوع منعاً باتاً أن تخبر العميل أنه "لا توجد منتجات" أو أنها "غير متوفرة حالياً" لأي صنف أو منتج مذكور في هذه القائمة، بل أجب بأنه متوفر واذكر له سعره وتفاصيله الموضحة أعلاه.
SECTION;
    }
}
