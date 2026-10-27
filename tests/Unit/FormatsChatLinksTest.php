<?php

use App\trait\FormatsChatLinks;

class DummyChatLinkHelper
{
    use FormatsChatLinks;
}

test('cleanChatLinks converts markdown link brackets into direct clickable urls', function () {
    $helper = new DummyChatLinkHelper;

    $input = 'أهلاً بك! يمكنك الطلب من خلال [الموقع الإلكتروني](https://smartego.org) أو تحميل [تطبيق أندرويد](https://play.google.com/store/apps/details?id=com.smartego).';
    $output = $helper->cleanChatLinks($input);

    expect($output)->not->toContain('[الموقع الإلكتروني]')
        ->and($output)->not->toContain('[تطبيق أندرويد]')
        ->and($output)->toContain('https://smartego.org')
        ->and($output)->toContain('https://play.google.com/store/apps/details?id=com.smartego');
});

test('cleanChatLinks removes square brackets wrapping a url', function () {
    $helper = new DummyChatLinkHelper;

    $input = 'تفضل بزيارة موقعنا: [https://smartego.org]';
    $output = $helper->cleanChatLinks($input);

    expect($output)->toBe('تفضل بزيارة موقعنا: https://smartego.org');
});

test('normalizeChatUrl adds https scheme if missing and strips brackets', function () {
    $helper = new DummyChatLinkHelper;

    expect($helper->normalizeChatUrl('smartego.org'))->toBe('https://smartego.org');
    expect($helper->normalizeChatUrl('www.smartego.org'))->toBe('https://www.smartego.org');
    expect($helper->normalizeChatUrl('https://smartego.org'))->toBe('https://smartego.org');
    expect($helper->normalizeChatUrl('[https://smartego.org]'))->toBe('https://smartego.org');
    expect($helper->normalizeChatUrl(null))->toBeNull();
});

test('formatOrderingLinksMessage produces clean, clickable direct urls on newlines', function () {
    $helper = new DummyChatLinkHelper;

    $msg = $helper->formatOrderingLinksMessage('smartego.org', 'play.google.com/test', 'apple.com/test');

    expect($msg)->toContain('https://smartego.org')
        ->and($msg)->toContain('https://play.google.com/test')
        ->and($msg)->toContain('https://apple.com/test')
        ->and($msg)->not->toContain('[')
        ->and($msg)->not->toContain(']');
});
