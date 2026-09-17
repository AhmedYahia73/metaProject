<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Chat;
use App\Models\Menue;
use App\Models\User;
use App\Models\MsgSend;
use App\Models\Order;

class HomeController extends Controller
{ 
    public function web_hook(Request $request)
    {
        try {

            $data = $request->all();
            $metadata = $data['entry'][0]['changes'][0]['value']['metadata'] ?? null;
            $phone_number_id = $metadata['phone_number_id'] ?? null;
            $user_data = User::where('phone_number_id', $phone_number_id)->first();
            $access_token = $user_data->access_token ?? null;

            $total_msgs = Order::
            where("from", "<=", date("Y-m-d"))
            ->where("to", ">=", date("Y-m-d"))
            ->sum("msgs");
            $from = Order::
            where("from", "<=", date("Y-m-d"))
            ->where("to", ">=", date("Y-m-d"))
            ->min("from");
            $to = Order::
            where("from", "<=", date("Y-m-d"))
            ->where("to", ">=", date("Y-m-d"))
            ->max("to");
            $total_sender = MsgSend::
            whereDate("created_at", ">=", $from)
            ->whereDate("created_at", "<=", $to)
            ->where("user_id", $user_data->id)
            ->count();
            if($total_msgs <= $total_sender ){
                return response()->json(['status' => 'limit_exceeded'], 200);

            }
            $message = $request->input('message');


            $instructions = <<<PROMPT
            أنت موظف خدمة عملاء لمطعم.

            مهمتك:
            - الرد باللغة العربية وبأسلوب ودود وبسيط.
            - مساعدة العميل في اختيار الوجبة المناسبة.
            - لا تخترع أي بيانات.
            - استخدم أداة search_foods للبحث في الوجبات.
            - لا تقترح الوجبات غير المتاحة.
            - إذا طلب العميل روابط المطعم أعطه الروابط الموجودة هنا.

            روابط المطعم:
            الموقع: {$user_data->url}
            Android: {$user_data->android_link}
            iOS: {$user_data->ios_link}

            لا تخترع أسعار أو أسماء أو خصومات.
            PROMPT;

            $tools = [
                [
                    'type' => 'function',
                    'name' => 'search_foods',
                    'description' => 'البحث في قاعدة بيانات الوجبات.',
                    'strict' => true,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'مصطلح البحث عن الوجبة.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 10,
                            ],
                        ],
                        'required' => ['query', 'limit'],
                        'additionalProperties' => false,
                    ],
                ],
            ];

            $response = OpenAI::responses()->create([
                'model' => 'gpt-5.5',
                'instructions' => $instructions,
                'tools' => $tools,
                'input' => $message,
            ]);

            // تنفيذ الـ tools التي طلبها الـAI
            $toolOutputs = [];

            foreach ($response->output as $item) {

                if ($item->type !== 'function_call') {
                    continue;
                }

                $name = $item->name ?? null;

                $args = json_decode(
                    $item->arguments ?? '{}',
                    true
                ) ?: [];

                if ($name === 'search_foods') {

                    $query = trim($args['query'] ?? '');
                    $limit = (int) ($args['limit'] ?? 10);

                    $foods = Food::query()
                        ->where('status', 1)
                        ->where('is_out_of_stock', 0)
                        ->where(function ($q) use ($query) {
                            $q->where('name_ar', 'like', "%{$query}%")
                            ->orWhere('description_ar', 'like', "%{$query}%");
                        })
                        ->limit($limit)
                        ->get([
                            'id',
                            'name_ar',
                            'description_ar',
                            'start_time',
                            'end_time',
                            'price',
                            'discount_type',
                            'discount_value',
                            'is_out_of_stock',
                        ]);

                    $toolOutputs[] = [
                        'type' => 'function_call_output',
                        'call_id' => $item->callId,
                        'output' => json_encode(
                            [
                                'foods' => $foods->toArray(),
                            ],
                            JSON_UNESCAPED_UNICODE
                        ),
                    ];
                }
            }

            // لو الـAI طلب بيانات من قاعدة البيانات
            if (!empty($toolOutputs)) {

                $response = OpenAI::responses()->create([
                    'model' => 'gpt-5.5',
                    'instructions' => $instructions,
                    'tools' => $tools,
                    'previous_response_id' => $response->id,
                    'input' => $toolOutputs,
                ]);
            }

            $reply = $response->outputText; 
  


            if (!$metadata) {
                return response()->json(['status' => 'ignored'], 200);
            }

 
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
                    'user_id' => $user_data->id ?? null,
                ]); 
 
            }

                // حفظ رسالة العميل في قاعدة البيانات
                Chat::create([
                    'name' => $senderName,
                    'phone' => $senderPhoneNumber,
                    'message' => $reply,
                    'is_image' => false,
                    'is_admin' => true,
                ]); 
                MsgSend::
                create([
                    'user_id' => $user_data->id ?? null,
                ]); 
            return response()->json(['status' => 'success'], 200);
        } catch (\Throwable $e) {
            Log::error("Webhook error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);
            // Return 200 to prevent Meta from retrying endlessly, or 500 depending on preference.
            // Meta recommends returning 200 even for errors to avoid them resending the same webhook.
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error'], 200);
        }
    }

    public function verify(Request $request)
    {
        $verifyToken = env('WHATSAPP_VERIFY_TOKEN');

        // محاولة قراءة المتغيرات سواء تم تحويل النقطة إلى شرطة سفلية أم لا
        $mode = $request->input('hub_mode') ?? $request->input('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->input('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->input('hub.challenge');

        // تسجيل البيانات في ملف laravel.log لمعرفة سبب الرفض
        \Illuminate\Support\Facades\Log::info('Meta Verification Debug:', [
            'expected_token_in_env' => $verifyToken,
            'received_token_from_meta' => $token,
            'received_mode' => $mode,
            'received_challenge' => $challenge,
            'all_url_parameters' => $request->all()
        ]);

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
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

// namespace App\Http\Controllers\api;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;
// use App\Models\Chat;
// use App\Models\Menue;

// class HomeController extends Controller
// { 
//     public function web_hook(Request $request)
//     {
//         // 1. التحقق الخاص بربط الـ Webhook مع منصة Meta (مهم جداً عند التفعيل)
//         if ($request->isMethod('get') && $request->has('hub_challenge')) {
//             return response($request->input('hub_challenge'), 200);
//         }

//         $data = $request->all();

//         // 2. معالجة الرسائل الواردة
//         if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
//             $message = $data['entry'][0]['changes'][0]['value']['messages'][0];
//             $senderPhoneNumber = $message['from']; 
//             $senderName = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'عميل جديد';
            
//             // جلب نص رسالة العميل
//             $userMessageText = trim($message['text']['body'] ?? '');

//             Log::info("Message received from: {$senderPhoneNumber}, Name: {$senderName}, Message: {$userMessageText}");

//             // حفظ رسالة العميل في قاعدة البيانات
//             Chat::create([
//                 'name' => $senderName,
//                 'phone' => $senderPhoneNumber, 
//                 'message' => $userMessageText,
//                 'is_image' => false, 
//                 'is_admin' => false,
//             ]);

//             // 3. توجيه الردود بناءً على كلمة العميل (منطق أسهل وأكثر دقة)
//             if ($userMessageText === 'طلب') {
//                 // إذا كتب العميل طلب، نسأله إذا كان يريد الموقع
//                 $this->sendFirstReplyChat($senderPhoneNumber, $senderName);

//             } elseif ($userMessageText === 'نعم') {
//                 // إذا وافق، نرسل له رابط الموقع
//                 $this->sendSecondReplyChat($senderPhoneNumber, $senderName);

//             } elseif ($userMessageText === 'لا') {
//                 // إذا رفض، نطلب منه كتابة الطلب في الشات مباشرة
//                 $this->sendTakeOrderChat($senderPhoneNumber, $senderName);

//             } else {
//                 // الوضع الافتراضي (أي رسالة أخرى مثل "مرحبا"): إرسال المنيو
//                 $this->sendImageMessage($senderPhoneNumber, $senderName);
//             }
//         }

//         return response()->json(['status' => 'success'], 200);
//     }
 
//     private function sendImageMessage($userPhoneNumber, $senderName)
//     {
//         $menus = Menue::get();
//         $response = null;
//         $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');
//         $token = env('WHATSAPP_ACCESS_TOKEN');

//         foreach ($menus as $menu) {
//             $imageUrl = url('storage/' . $menu->image);
                
//             $payload = [
//                 "messaging_product" => "whatsapp",
//                 "recipient_type" => "individual",
//                 "to" => $userPhoneNumber,
//                 "type" => "image",
//                 "image" => [
//                     "link" => $imageUrl, 
//                     "caption" => "مرحبا بحضرتك ده المنيو بتاع المطعم، تقدر دلوقتي تطلب.. لو عاوز تطلب برجاء كتابة كلمة 'طلب'"
//                 ]
//             ];

//             $response = Http::withToken($token)
//                 ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", $payload);
            
//             Chat::create([
//                 'name' => $senderName,
//                 'phone' => $userPhoneNumber,
//                 'message' => 'Image sent: ' . $imageUrl,
//                 'is_image' => true,
//                 'is_admin' => true,
//             ]);
//         }

//         return $response ? $response->json() : ['status' => 'no_menus'];
//     }
 
//     private function sendFirstReplyChat($userPhoneNumber, $senderName)
//     {
//         $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');
//         $bodyText = "للحصول على عروض أكثر يمكنك الطلب عن طريق الموقع الإلكتروني.. للحصول على الموقع اكتب 'نعم'، للطلب من هنا اكتب 'لا'";

//         $payload = [
//             "messaging_product" => "whatsapp",
//             "recipient_type" => "individual",
//             "to" => $userPhoneNumber,
//             "type" => "text",
//             "text" => [ 
//                 "body" => $bodyText
//             ]
//         ];

//         $response = Http::withToken(env('WHATSAPP_ACCESS_TOKEN'))
//             ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", $payload);
        
//         Chat::create([
//             'name' => $senderName,
//             'phone' => $userPhoneNumber, 
//             'message' => $bodyText, // تم تصحيح المتغير هنا
//             'is_image' => false, 
//             'is_admin' => true,
//         ]);

//         return $response->json();
//     }

//     private function sendSecondReplyChat($userPhoneNumber, $senderName)
//     {
//         $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');
//         $bodyText = "مرحبا بحضرتك، ده لينك الموقع الإلكتروني: \n https://keeto.org/";

//         $payload = [
//             "messaging_product" => "whatsapp",
//             "recipient_type" => "individual",
//             "to" => $userPhoneNumber,
//             "type" => "text",
//             "text" => [ 
//                 "body" => $bodyText 
//             ]
//         ];

//         $response = Http::withToken(env('WHATSAPP_ACCESS_TOKEN'))
//             ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", $payload);
        
//         Chat::create([
//             'name' => $senderName,
//             'phone' => $userPhoneNumber, 
//             'message' => 'Sent order link',
//             'is_image' => false, 
//             'is_admin' => true,
//         ]);

//         return $response->json();
//     }
    
//     // أضفت هذه الدالة في حال كتب العميل "لا" ويريد الطلب من الواتساب
//     private function sendTakeOrderChat($userPhoneNumber, $senderName)
//     {
//         $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', 'YOUR_PHONE_NUMBER_ID');
//         $bodyText = "تحت أمرك، برجاء كتابة طلبك هنا وسيقوم أحد ممثلي خدمة العملاء بمراجعة الطلب معك فوراً.";

//         $payload = [
//             "messaging_product" => "whatsapp",
//             "recipient_type" => "individual",
//             "to" => $userPhoneNumber,
//             "type" => "text",
//             "text" => [ 
//                 "body" => $bodyText
//             ]
//         ];

//         $response = Http::withToken(env('WHATSAPP_ACCESS_TOKEN'))
//             ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", $payload);
        
//         Chat::create([
//             'name' => $senderName,
//             'phone' => $userPhoneNumber, 
//             'message' => $bodyText,
//             'is_image' => false, 
//             'is_admin' => true,
//         ]);

//         return $response->json();
//     }
// }