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
     * Fetch public products from the configured api_link.
     * Appends /api/public/products to the api_link.
     *
     * @param  object|string|null  $itemOrLink  Model with api_link attribute or raw URL string
     * @return string|null Formatted string containing products for AI, or null
     */
    public function fetchProductsFromApiLink(object|string|null $itemOrLink): ?string
    {
        $apiLink = is_object($itemOrLink) ? ($itemOrLink->api_link ?? null) : $itemOrLink;

        if (empty($apiLink) || ! is_string($apiLink)) {
            return null;
        }

        $trimmed = trim($apiLink);
        if (empty($trimmed)) {
            return null;
        }

        // Ensure proper scheme
        if (! preg_match('~^https?://~i', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        $url = rtrim($trimmed, '/').'/api/public/products';
        $cacheKey = 'products_api_data_'.md5($url);

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
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
                $formatted = ! empty($data)
                    ? (is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string) $data)
                    : trim((string) $response->body());

                if (! empty($formatted)) {
                    Cache::put($cacheKey, $formatted, 60);

                    return $formatted;
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
     * Build the API products section for the AI prompt.
     */
    public function buildApiProductsPromptSection(object|string|null $itemOrLink): string
    {
        $products = $this->fetchProductsFromApiLink($itemOrLink);
        if (empty($products)) {
            return '';
        }

        return "\n\nقائمة وبيانات المنتجات المتاحة من النظام (Products API):\n".$products."\n\nتنبيه هام جداً: هذه هي المنتجات والأسعار والتفاصيل المعتمدة والحالية، أجب منها والتزم بها بدقة في الرد على استفسارات العميل بخصوص الأصناف والمنتجات والأسعار المتاحة، ولا تخترع أي أسعار أو أصناف غير موجودة.";
    }
}
