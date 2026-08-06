<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Chat;
use App\Models\Menue;

class HomeController extends Controller
{ 
    public function web_hook(Request $request)
    {
        // 1. التحقق الخاص بربط الـ Webhook مع منصة Meta
        if ($request->isMethod('get') && $request->has('hub_challenge')) {
            return response($request->input('hub_challenge'), 200);
        }

        $data = $request->all();

        // 2. معالجة الرسائل الواردة
        if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
            $message = $data['entry'][0]['changes'][0]['value']['messages'][0];
            $senderPhoneNumber = $message['from']; 
            $senderName = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'عميل جديد';
            
            // جلب نص رسالة العميل
            $userMessageText = trim($message['text']['body'] ?? '');

            Log::info("Message received from: {$senderPhoneNumber}, Name: {$senderName}, Message: {$userMessageText}");

            // حفظ رسالة العميل في قاعدة البيانات
            Chat::create([
                'name' => $senderName,
                'phone' => $senderPhoneNumber, 
                'message' => $userMessageText,
                'is_image' => false, 
                'is_admin' => false,
            ]);

            // --- التحديث الجديد: التحقق من احتواء الرسالة على كلمات تدل على الطلب ---
            
            // مصفوفة تحتوي على كل الكلمات المحتملة للطلب (يمكنك إضافة المزيد هنا)
            $orderKeywords = ['طلب', 'اطلب', 'أطلب', 'طالب', 'اوردر', 'أوردر', 'order'];
            $wantsToOrder = false;

            // البحث داخل رسالة العميل عن أي من الكلمات المفتاحية
            foreach ($orderKeywords as $keyword) {
                // استخدام دالة str_contains لـ PHP 8
                // أو نستخدم mb_strpos لدعم أفضل للغة العربية إذا كنت تستخدم إصدار قديم
                if (mb_strpos($userMessageText, $keyword) !== false) {
                    $wantsToOrder = true;
                    break; // نوقف البحث بمجرد العثور على أول تطابق
                }
            }

            // 3. توجيه الردود
            if ($wantsToOrder) {
                // إذا كانت الرسالة تحتوي على كلمة طلب أو مشابهاتها
                $this->sendFirstReplyChat($senderPhoneNumber, $senderName);

            } elseif ($userMessageText === 'نعم' || $userMessageText === 'اه' || $userMessageText === 'ايوه') {
                // أضفت لك بعض المرونة هنا أيضاً في كلمة "نعم"
                $this->sendSecondReplyChat($senderPhoneNumber, $senderName);

            } elseif ($userMessageText === 'لا' || $userMessageText === 'لاء') {
                // مرونة في كلمة "لا"
                $this->sendTakeOrderChat($senderPhoneNumber, $senderName);

            } else {
                // الوضع الافتراضي (أي رسالة أخرى): إرسال المنيو
                $this->sendImageMessage($senderPhoneNumber, $senderName);
            }
        }

        return response()->json(['status' => 'success'], 200);
    }
 
    private function sendImageMessage($userPhoneNumber, $senderName)
    {
        $menus = Menue::get();
        $response = null;
        $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');
        $token = env('WHATSAPP_ACCESS_TOKEN');

        foreach ($menus as $menu) {
            $imageUrl = url('storage/' . $menu->image);
                
            $payload = [
                "messaging_product" => "whatsapp",
                "recipient_type" => "individual",
                "to" => $userPhoneNumber,
                "type" => "image",
                "image" => [
                    "link" => $imageUrl, 
                    "caption" => "مرحبا بحضرتك ده المنيو بتاع المطعم، تقدر دلوقتي تطلب.. لو عاوز تطلب برجاء كتابة كلمة 'طلب'"
                ]
            ];

            $response = Http::withToken($token)
                ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", $payload);
            
            Chat::create([
                'name' => $senderName,
                'phone' => $userPhoneNumber,
                'message' => 'Image sent: ' . $imageUrl,
                'is_image' => true,
                'is_admin' => true,
            ]);
        }

        return $response ? $response->json() : ['status' => 'no_menus'];
    }
 
    private function sendFirstReplyChat($userPhoneNumber, $senderName)
    {
        $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');
        $bodyText = "للحصول على عروض أكثر يمكنك الطلب عن طريق الموقع الإلكتروني.. للحصول على الموقع اكتب 'نعم'، للطلب من هنا اكتب 'لا'";

        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $userPhoneNumber,
            "type" => "text",
            "text" => [ 
                "body" => $bodyText
            ]
        ];

        $response = Http::withToken(env('WHATSAPP_ACCESS_TOKEN'))
            ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", $payload);
        
        Chat::create([
            'name' => $senderName,
            'phone' => $userPhoneNumber, 
            'message' => $bodyText, 
            'is_image' => false, 
            'is_admin' => true,
        ]);

        return $response->json();
    }

    private function sendSecondReplyChat($userPhoneNumber, $senderName)
    {
        $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');
        $bodyText = "مرحبا بحضرتك، ده لينك الموقع الإلكتروني: \n https://keeto.org/";

        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $userPhoneNumber,
            "type" => "text",
            "text" => [ 
                "body" => $bodyText 
            ]
        ];

        $response = Http::withToken(env('WHATSAPP_ACCESS_TOKEN'))
            ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", $payload);
        
        Chat::create([
            'name' => $senderName,
            'phone' => $userPhoneNumber, 
            'message' => 'Sent order link',
            'is_image' => false, 
            'is_admin' => true,
        ]);

        return $response->json();
    }
    
    private function sendTakeOrderChat($userPhoneNumber, $senderName)
    {
        $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');
        $bodyText = "تحت أمرك، برجاء كتابة طلبك هنا وسيقوم أحد ممثلي خدمة العملاء بمراجعة الطلب معك فوراً.";

        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $userPhoneNumber,
            "type" => "text",
            "text" => [ 
                "body" => $bodyText
            ]
        ];

        $response = Http::withToken(env('WHATSAPP_ACCESS_TOKEN'))
            ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", $payload);
        
        Chat::create([
            'name' => $senderName,
            'phone' => $userPhoneNumber, 
            'message' => $bodyText,
            'is_image' => false, 
            'is_admin' => true,
        ]);

        return $response->json();
    }
}