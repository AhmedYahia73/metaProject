# توثيق ومراجعة واجهات الاشتراك المباشر وإلغاء الاشتراك (Subscription & Unsubscription APIs)

هذا المستند يحتوي على:
1. **المراجعة الفنية الشاملة لكود الكنترولرز الخمسة مع رصد الثغرات والأخطاء المنطقية.**
2. **الكود المصحح والروتس الواجب إضافتها في `routes/api.php`.**
3. **التوثيق الكامل لجميع واجهات برمجة التطبيقات (API Documentation): الشرح، الروابط، الـ Headers، الـ Body، الـ Responses الناجحة والأخطاء.**
4. **جدول مرجعي سريع (Cheat Sheet) وأمثلة استدعاء للواجهة الأمامية (Frontend Examples).**

---

## الفهرس
1. [المراجعة البرمجية ورصد الثغرات والأخطاء](#1-المراجعة-البرمجية-ورصد-الثغرات-والأخطاء)
2. [الروتس المطلوبة في `routes/api.php`](#2-الروتس-المطلوبة-في-routesapiphp)
3. [الكود البرمجي المصحح للكنترولرز](#3-الكود-البرمجي-المصحح-للكنترولرز)
4. [التوثيق التفصيلي لواجهات الـ APIs](#4-التوثيق-التفصيلي-لواجهات-الـ-apis)
   - [TikTok Account APIs](#41-tiktok-account-apis)
   - [Telegram Account APIs](#42-telegram-account-apis)
   - [Instagram Pages APIs](#43-instagram-pages-apis)
   - [Messenger Pages APIs](#44-messenger-pages-apis)
   - [WhatsApp Pages / Items APIs](#45-whatsapp-pages--items-apis)
5. [جدول المقارنة والمرجع السريع (Cheat Sheet)](#5-جدول-المقارنة-والمرجع-السريع-cheat-sheet)
6. [أمثلة كود للواجهة الأمامية (JavaScript / Axios)](#6-أمثلة-كود-للواجهة-الأمامية-javascript--axios)

---

## 1. المراجعة البرمجية ورصد الثغرات والأخطاء

بعد فحص الكود في الكنترولرز الخمسة، تم رصد عدة مشاكل وثغرات أمنية ومنطقية يجب تصحيحها:

### 1.1 ثغرة أمنية حرجة (IDOR - Insecure Direct Object Reference) ⚠️
* **في `InstagramPagesController::unSubscription`**:
  البحث عن الحساب كان يتم بالشكل التالي:
  ```php
  $instagramItem = InstagramItem::where('instagram_id', $validated['instagram_id'])->firstOrFail();
  ```
  **الخلل:** لا يوجد فحص لـ `user_id`، مما يسمح لأي مستخدم مسجل بإرسال `instagram_id` لأي مستخدم آخر وإلغاء اشتراكه وتصفير رسائله!
  **الحل:** إلزام الفحص بملكية الحساب: `InstagramItem::where('user_id', $user->id)->where('instagram_id', $validated['instagram_id'])->firstOrFail();`.

* **في `MessengerPagesController::unSubscription`**:
  نفس الثغرة بالضبط:
  ```php
  $existingAccount = MessengerAccount::where('page_id', $validated['page_id'])->firstOrFail();
  ```
  **الحل:** إلزام الفحص بملكية الصفحة: `MessengerAccount::where('user_id', $user->id)->where('page_id', $validated['page_id'])->firstOrFail();`.

---

### 1.2 خطأ منطقي يمنع مستخدمي الواتساب من إلغاء الاشتراك 🛑
* **في `WhatsPagesController::unSubscription`**:
  تم نسخ الكود التالي بالخطأ من صفحات فيسبوك:
  ```php
  if (empty($user->facebook_access_token)) {
      return response()->json([
          'status' => false,
          'message' => 'Your account is not linked to Facebook/Instagram. Please login via Facebook/Instagram first.',
      ], Response::HTTP_FORBIDDEN);
  }
  ```
  **الخلل:** أرقام الواتساب مفعلة عبر OTP أو تسجيل مباشر برقم الهاتف، ولا تشترط ربط حساب فيسبوك للمستخدم (وحتى دالة `directSubscription` للواتساب لا تطلب هذا الشرط). وجود هذا الفحص يمنع أي مستخدم واتساب من إلغاء اشتراكه ويعيد له خطأ 403 غير مبرر.
  **الحل:** حذف هذا الفحص تماماً من دالة الواتساب.

---

### 1.3 نصوص الاستجابة وأكواد الـ HTTP خاطئة في جميع دوال `unSubscription` ❌
* في جميع دوال `unSubscription` الخمسة:
  * نص الرسالة هو: `'YOU SUBSCRIPED SUCCESS.'` رغم أن العملية هي **إلغاء اشتراك**! (مع خطأ إملائي لكلمة Subscribed).
  * كود الحالة هو `201 Response::HTTP_CREATED` بينما يجب أن يكون `200 Response::HTTP_OK` لأنها عملية تعديل وإلغاء اشتراك وليست إنشاء مورد جديد.
  * **الحل:** توحيد الرسالة إلى `'YOU UNSUBSCRIBED SUCCESSFULLY.'` وكود الحالة `200 OK`.

---

### 1.4 خطأ تاريخ انتهاء الصلاحية في `MessengerPagesController::directSubscription` ⚠️
* في السطر 565:
  ```php
  'end_date' => now()->addDays(00),
  ```
  تمت كتابة `addDays(00)` مما يجعل الاشتراك ينتهي في نفس يوم بدايته!
  **الحل:** تصحيحها إلى `now()->addDays(30)`.

---

### 1.5 مسارات الروابط (Routes) مفقودة في `routes/api.php` 🔌
* جميع دوال `unSubscription` الخمسة **غير مسجلة في ملف الروتس نهائياً**.
* دوال `directSubscription` لـ TikTok و Telegram أيضاً **غير مسجلة**.
* إرسال أي طلب لهذه المسارات حالياً يعيد `404 Not Found`.

---

## 2. الروتس المطلوبة في `routes/api.php`

يتم وضع هذه الروتس داخل مجموعة مسارات المستخدم المصادق عليها:
`Route::prefix('user')->middleware(['auth:sanctum', 'user'])->group(function () { ... });`

```php
// ==========================================
// 1. Messenger Self-Service
// ==========================================
Route::post('messenger/directSubscription', [MessengerPagesController::class, 'directSubscription']);
Route::post('messenger/unSubscription', [MessengerPagesController::class, 'unSubscription']);

// ==========================================
// 2. WhatsApp Self-Service
// ==========================================
Route::post('whats/directSubscription', [WhatsPagesController::class, 'directSubscription']);
Route::post('whats/unSubscription', [WhatsPagesController::class, 'unSubscription']);

// ==========================================
// 3. Instagram Self-Service
// ==========================================
Route::post('instagram/directSubscription', [InstagramPagesController::class, 'directSubscription']);
Route::post('instagram/unSubscription', [InstagramPagesController::class, 'unSubscription']);

// ==========================================
// 4. Telegram Self-Service
// ==========================================
Route::post('telegram/directSubscription', [UserTelegramAccountController::class, 'directSubscription']);
Route::post('telegram/unSubscription', [UserTelegramAccountController::class, 'unSubscription']);

// ==========================================
// 5. TikTok Self-Service
// ==========================================
Route::post('tiktok/directSubscription', [UserTikTokAccountController::class, 'directSubscription']);
Route::post('tiktok/unSubscription', [UserTikTokAccountController::class, 'unSubscription']);
```

---

## 3. الكود البرمجي المصحح للكنترولرز

### 3.1 `TikTokAccountController.php`
```php
public function directSubscription(Request $request): JsonResponse
{
    $user = $request->user();

    $validated = $request->validate([
        'tiktok_id' => 'required|exists:tiktok_accounts,id',
    ]);

    $tiktok = TikTokAccount::where('user_id', $user->id)
        ->findOrFail($validated['tiktok_id']);

    $tiktok->update([
        'status' => 'active',
        'msg_number' => 30000,
        'start_date' => now(),
        'end_date' => now()->addDays(30),
    ]);

    return response()->json([
        'status' => true,
        'message' => 'YOU SUBSCRIBED SUCCESSFULLY.',
    ], Response::HTTP_OK);
}

public function unSubscription(Request $request): JsonResponse
{
    $user = $request->user();

    $validated = $request->validate([
        'tiktok_id' => 'required|exists:tiktok_accounts,id',
    ]);

    $tiktok = TikTokAccount::where('user_id', $user->id)
        ->findOrFail($validated['tiktok_id']);

    $tiktok->update([
        'msg_number' => 0,
        'start_date' => null,
        'end_date' => null,
    ]);

    return response()->json([
        'status' => true,
        'message' => 'YOU UNSUBSCRIBED SUCCESSFULLY.',
    ], Response::HTTP_OK);
}
```

### 3.2 `TelegramAccountController.php`
```php
public function directSubscription(Request $request): JsonResponse
{
    $user = $request->user();

    $validated = $request->validate([
        'telegram_id' => 'required|exists:telegram_accounts,id',
    ]);

    $telegram = TelegramAccount::where('user_id', $user->id)
        ->findOrFail($validated['telegram_id']);

    $telegram->update([
        'status' => 'active',
        'msg_number' => 30000,
        'start_date' => now(),
        'end_date' => now()->addDays(30),
    ]);

    return response()->json([
        'status' => true,
        'message' => 'YOU SUBSCRIBED SUCCESSFULLY.',
    ], Response::HTTP_OK);
}

public function unSubscription(Request $request): JsonResponse
{
    $user = $request->user();

    $validated = $request->validate([
        'telegram_id' => 'required|exists:telegram_accounts,id',
    ]);

    $telegram = TelegramAccount::where('user_id', $user->id)
        ->findOrFail($validated['telegram_id']);

    $telegram->update([
        'msg_number' => 0,
        'start_date' => null,
        'end_date' => null,
    ]);

    return response()->json([
        'status' => true,
        'message' => 'YOU UNSUBSCRIBED SUCCESSFULLY.',
    ], Response::HTTP_OK);
}
```

### 3.3 `InstagramPagesController.php`
```php
public function unSubscription(Request $request): JsonResponse
{
    $user = $request->user();

    if (empty($user->facebook_access_token)) {
        return response()->json([
            'status' => false,
            'message' => 'Your account is not linked to Facebook/Instagram. Please login via Facebook/Instagram first.',
        ], Response::HTTP_FORBIDDEN);
    }

    $validated = $request->validate([
        'instagram_id' => 'required|string|max:255',
    ]);

    // تأمين الاستعلام بربطه مع مستخدم الجلسة
    $instagramItem = InstagramItem::where('user_id', $user->id)
        ->where('instagram_id', $validated['instagram_id'])
        ->firstOrFail();

    $instagramItem->update([
        'msg_number' => 0,
        'start_date' => null,
        'end_date' => null,
    ]);

    return response()->json([
        'status' => true,
        'message' => 'YOU UNSUBSCRIBED SUCCESSFULLY.',
    ], Response::HTTP_OK);
}
```

### 3.4 `MessengerPagesController.php`
```php
public function unSubscription(Request $request): JsonResponse
{
    $user = $request->user();

    if (empty($user->facebook_access_token)) {
        return response()->json([
            'status' => false,
            'message' => 'Your account is not linked to Facebook. Please login via Facebook first.',
        ], Response::HTTP_FORBIDDEN);
    }

    $validated = $request->validate([
        'page_id' => 'required|string|max:255',
    ]);

    // تأمين الاستعلام بربطه مع مستخدم الجلسة
    $existingAccount = MessengerAccount::where('user_id', $user->id)
        ->where('page_id', $validated['page_id'])
        ->firstOrFail();

    $existingAccount->update([
        'msg_number' => 0,
        'start_date' => null,
        'end_date' => null,
    ]);

    return response()->json([
        'status' => true,
        'message' => 'YOU UNSUBSCRIBED SUCCESSFULLY.',
    ], Response::HTTP_OK);
}
```

### 3.5 `WhatsPagesController.php`
```php
public function unSubscription(Request $request): JsonResponse
{
    $user = $request->user();

    $validated = $request->validate([
        'whats_item_id' => 'required|exists:whats_items,id',
    ]);

    // تم حذف فحص facebook_access_token لأنه غير مطلوب لأرقام واتساب
    $whatsItem = WhatsItem::where('user_id', $user->id)
        ->findOrFail($validated['whats_item_id']);

    $whatsItem->update([
        'msg_number' => 0,
        'start_date' => null,
        'end_date' => null,
    ]);

    return response()->json([
        'status' => true,
        'message' => 'YOU UNSUBSCRIBED SUCCESSFULLY.',
    ], Response::HTTP_OK);
}
```

---

## 4. التوثيق التفصيلي لواجهات الـ APIs

### المصادقة والـ Headers المشتركة
جميع المسارات تتطلب إرسال توكن المصادقة الخاص بالمستخدم المسجل:
```http
Authorization: Bearer <USER_SANCTUM_TOKEN>
Accept: application/json
Content-Type: application/json
```

---

### 4.1 TikTok Account APIs

#### 1. الاشتراك المباشر لـ TikTok (`directSubscription`)
* **URL:** `POST /api/user/tiktok/directSubscription`
* **الشرح:** يقوم بتفعيل اشتراك فوري لحساب تيك توك محدد للمستخدم مع منح 30,000 رسالة وصلاحية لمدة 30 يوماً من تاريخ الطلب.
* **Request Body:**
  ```json
  {
      "tiktok_id": 1
  }
  ```
  * `tiktok_id` (إلزامي | integer): المعرف الرقمي للحساب في جدول `tiktok_accounts`.
* **Success Response (200 OK / 201 Created):**
  ```json
  {
      "status": true,
      "message": "YOU SUBSCRIBED SUCCESSFULLY."
  }
  ```
* **Error Responses:**
  * **401 Unauthorized:**
    ```json
    { "message": "Unauthenticated." }
    ```
  * **404 Not Found (الحساب غير موجود أو يتبع مستخدم آخر):**
    ```json
    { "message": "No query results for model [App\\Models\\TikTokAccount]." }
    ```
  * **422 Unprocessable Entity:**
    ```json
    {
        "message": "The tiktok id field is required.",
        "errors": {
            "tiktok_id": ["The tiktok id field is required."]
        }
    }
    ```

#### 2. إلغاء الاشتراك لـ TikTok (`unSubscription`)
* **URL:** `POST /api/user/tiktok/unSubscription`
* **الشرح:** يقوم بإلغاء الاشتراك لحساب تيك توك، بتصفير عدد الرسائل وإلغاء تواريخ البداية والنهاية.
* **Request Body:**
  ```json
  {
      "tiktok_id": 1
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
      "status": true,
      "message": "YOU UNSUBSCRIBED SUCCESSFULLY."
  }
  ```
* **Error Responses:**
  * **404 Not Found:** الحساب غير مسجل أو لا يملكه المستخدم الحالي.
  * **422 Unprocessable Entity:** الحقل مطلوب أو الحساب غير موجود في قاعدة البيانات.

---

### 4.2 Telegram Account APIs

#### 1. الاشتراك المباشر لـ Telegram (`directSubscription`)
* **URL:** `POST /api/user/telegram/directSubscription`
* **الشرح:** تفعيل اشتراك فوري لبوت التيليجرام الخاص بالمستخدم، بتعيين رصيد رسائل 30,000 رسالة وصلاحية 30 يوماً.
* **Request Body:**
  ```json
  {
      "telegram_id": 2
  }
  ```
  * `telegram_id` (إلزامي | integer): المعرف الرقمي للبوت في جدول `telegram_accounts`.
* **Success Response (200 OK / 201 Created):**
  ```json
  {
      "status": true,
      "message": "YOU SUBSCRIBED SUCCESSFULLY."
  }
  ```
* **Error Responses:**
  * **404 Not Found:** البوت غير مسجل لهذا المستخدم.
  * **422 Unprocessable Entity:** عدم إرسال المعرف.

#### 2. إلغاء الاشتراك لـ Telegram (`unSubscription`)
* **URL:** `POST /api/user/telegram/unSubscription`
* **الشرح:** إلغاء الاشتراك وتصفير رصيد رسائل بوت التيليجرام ومسح التواريخ.
* **Request Body:**
  ```json
  {
      "telegram_id": 2
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
      "status": true,
      "message": "YOU UNSUBSCRIBED SUCCESSFULLY."
  }
  ```
* **Error Responses:**
  * **404 Not Found:** الحساب غير موجود للمستخدم الحالي.

---

### 4.3 Instagram Pages APIs

#### إلغاء الاشتراك لـ Instagram (`unSubscription`)
* **URL:** `POST /api/user/instagram/unSubscription`
* **الشرح:** إلغاء اشتراك حساب إنستجرام بيزنس وتصفير الرسائل. يتطلب أن يكون المستخدم رابطاً حسابه بفيسبوك.
* **Request Body:**
  ```json
  {
      "instagram_id": "17841405309211111"
  }
  ```
  * `instagram_id` (إلزامي | string): معرف حساب إنستجرام لدى Meta (وليس رقم السجل الداخلي في الداتابيز).
* **Success Response (200 OK):**
  ```json
  {
      "status": true,
      "message": "YOU UNSUBSCRIBED SUCCESSFULLY."
  }
  ```
* **Error Responses:**
  * **403 Forbidden (المستخدم غير رابط حسابه بفيسبوك):**
    ```json
    {
        "status": false,
        "message": "Your account is not linked to Facebook/Instagram. Please login via Facebook/Instagram first."
    }
    ```
  * **404 Not Found (حساب الإنستجرام غير مسجل لهذا المستخدم):**
    ```json
    { "message": "No query results for model [App\\Models\\InstagramItem]." }
    ```
  * **422 Unprocessable Entity:**
    ```json
    {
        "message": "The instagram id field is required.",
        "errors": {
            "instagram_id": ["The instagram id field is required."]
        }
    }
    ```

---

### 4.4 Messenger Pages APIs

#### إلغاء الاشتراك لـ Messenger (`unSubscription`)
* **URL:** `POST /api/user/messenger/unSubscription`
* **الشرح:** إلغاء اشتراك صفحة فيسبوك ماسنجر وتصفير رسائلها. يتطلب وجود `facebook_access_token` للمستخدم.
* **Request Body:**
  ```json
  {
      "page_id": "109876543210987"
  }
  ```
  * `page_id` (إلزامي | string): معرف صفحة فيسبوك لدى Meta.
* **Success Response (200 OK):**
  ```json
  {
      "status": true,
      "message": "YOU UNSUBSCRIBED SUCCESSFULLY."
  }
  ```
* **Error Responses:**
  * **403 Forbidden:**
    ```json
    {
        "status": false,
        "message": "Your account is not linked to Facebook. Please login via Facebook first."
    }
    ```
  * **404 Not Found:** الصفحة غير مضافة لدى المستخدم.
  * **422 Unprocessable Entity:** عدم إرسال `page_id`.

---

### 4.5 WhatsApp Pages / Items APIs

#### إلغاء الاشتراك لـ WhatsApp (`unSubscription`)
* **URL:** `POST /api/user/whats/unSubscription`
* **الشرح:** إلغاء اشتراك رقم واتساب مسجل للمستخدم وتصفير رصيد رسائله. لا يشترط ربط فيسبوك.
* **Request Body:**
  ```json
  {
      "whats_item_id": 3
  }
  ```
  * `whats_item_id` (إلزامي | integer): معرف رقم الواتساب في جدول `whats_items`.
* **Success Response (200 OK):**
  ```json
  {
      "status": true,
      "message": "YOU UNSUBSCRIBED SUCCESSFULLY."
  }
  ```
* **Error Responses:**
  * **404 Not Found:** رقم الواتساب غير موجود أو لا يخص هذا المستخدم.
  * **422 Unprocessable Entity:**
    ```json
    {
        "message": "The whats item id field is required.",
        "errors": {
            "whats_item_id": ["The whats item id field is required."]
        }
    }
    ```

---

## 5. جدول المقارنة والمرجع السريع (Cheat Sheet)

| المنصة | الدالة | نوع الطلب | Endpoint الكامل | المعامل في الـ Body | نوع المعامل |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TikTok** | اشتراك مباشر | `POST` | `/api/user/tiktok/directSubscription` | `tiktok_id` | `integer` (DB ID) |
| **TikTok** | إلغاء اشتراك | `POST` | `/api/user/tiktok/unSubscription` | `tiktok_id` | `integer` (DB ID) |
| **Telegram** | اشتراك مباشر | `POST` | `/api/user/telegram/directSubscription` | `telegram_id` | `integer` (DB ID) |
| **Telegram** | إلغاء اشتراك | `POST` | `/api/user/telegram/unSubscription` | `telegram_id` | `integer` (DB ID) |
| **Instagram** | اشتراك مباشر | `POST` | `/api/user/instagram/directSubscription` | `instagram_id` | `string` (Meta ID) |
| **Instagram** | إلغاء اشتراك | `POST` | `/api/user/instagram/unSubscription` | `instagram_id` | `string` (Meta ID) |
| **Messenger** | اشتراك مباشر | `POST` | `/api/user/messenger/directSubscription` | `page_id` | `string` (Meta ID) |
| **Messenger** | إلغاء اشتراك | `POST` | `/api/user/messenger/unSubscription` | `page_id` | `string` (Meta ID) |
| **WhatsApp** | اشتراك مباشر | `POST` | `/api/user/whats/directSubscription` | `whats_item_id` | `integer` (DB ID) |
| **WhatsApp** | إلغاء اشتراك | `POST` | `/api/user/whats/unSubscription` | `whats_item_id` | `integer` (DB ID) |

---

## 6. أمثلة كود للواجهة الأمامية (JavaScript / Axios)

```javascript
import axios from 'axios';

const api = axios.create({
  baseURL: 'https://your-domain.com/api/user',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  }
});

// تعيين توكن المستخدم تلقائياً
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('user_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// 1. تفعيل وإلغاء TikTok
export const subscribeTikTok = (tiktokId) => api.post('/tiktok/directSubscription', { tiktok_id: tiktokId });
export const unsubscribeTikTok = (tiktokId) => api.post('/tiktok/unSubscription', { tiktok_id: tiktokId });

// 2. تفعيل وإلغاء Telegram
export const subscribeTelegram = (telegramId) => api.post('/telegram/directSubscription', { telegram_id: telegramId });
export const unsubscribeTelegram = (telegramId) => api.post('/telegram/unSubscription', { telegram_id: telegramId });

// 3. إلغاء Instagram
export const unsubscribeInstagram = (instagramId) => api.post('/instagram/unSubscription', { instagram_id: instagramId });

// 4. إلغاء Messenger
export const unsubscribeMessenger = (pageId) => api.post('/messenger/unSubscription', { page_id: pageId });

// 5. إلغاء WhatsApp
export const unsubscribeWhatsApp = (whatsItemId) => api.post('/whats/unSubscription', { whats_item_id: whatsItemId });
```
