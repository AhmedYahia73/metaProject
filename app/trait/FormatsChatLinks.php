<?php

namespace App\trait;

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
}
