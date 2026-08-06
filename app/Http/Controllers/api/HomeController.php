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
        $data = $request->all();

        if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
            $message = $data['entry'][0]['changes'][0]['value']['messages'][0];
            $senderPhoneNumber = $message['from']; 
            $senderName = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'عميل جديد';

            Log::info("Message received from: " . $senderPhoneNumber . " Name: " . $senderName);

            // جلب آخر رسالة من الإدمن لهذا الرقم
            $chat = Chat::where('phone', $senderPhoneNumber)
                ->where("is_admin", true)
                ->orderByDesc("id")
                ->first();

            // التحقق مما إذا كان هناك شات سابق وما إذا كانت آخر رسالة هي صورة
            if ($chat && $chat->is_image) {
                $this->sendReplyChat($senderPhoneNumber, $senderName);
            } else {
                $this->sendImageMessage($senderPhoneNumber, $senderName);
            }
        }

        return response()->json(['status' => 'success'], 200);
    }
 
    private function sendImageMessage($userPhoneNumber, $senderName)
    {
        $menus = Menue::get();
        $response = null;

        // يفضل وضع YOUR_PHONE_NUMBER_ID في ملف .env
        $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');

        foreach ($menus as $menu) {
            $imageUrl = url('storage/' . $menu->image);
                
            $payload = [
                "messaging_product" => "whatsapp",
                "recipient_type" => "individual",
                "to" => $userPhoneNumber,
                "type" => "image",
                "image" => [
                    "link" => $imageUrl, 
                    "caption" => "مرحبا بحضرتك ده المنيو بتاع المطعم تقدر دلوقت تطلب لو عاوز تطلب برجاء كتابة طلب"
                ]
            ];

            $response = Http::withToken(env('WHATSAPP_ACCESS_TOKEN'))
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
 
    private function sendReplyChat($userPhoneNumber, $senderName)
    {
        $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');

        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $userPhoneNumber,
            "type" => "text",
            "text" => [ 
                "body" => "مرحبا بحضرتك ده لينك لاختيار الوجبة المناسبة" . PHP_EOL . "https://keeto.org/" 
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
}