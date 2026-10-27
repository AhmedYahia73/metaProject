# دليل تكامل TikTok للوحة تحكم الإدارة (Admin API & Management Guide)

هذا الدليل مخصص لمطوري لوحة تحكم الإدارة (**Admin Dashboard Developers**) ومسؤولي النظام لإدارة حسابات تيك توك وتتبع استهلاك رسائل الذكاء الاصطناعي وتفعيل/تعطيل الحسابات وتجديد الباقات.

---

## الفهرس
1. [المصادقة وصلاحيات الأدمن (Admin Authentication)](#1-المصادقة-وصلاحيات-الأدمن-admin-authentication)
2. [المتغيرات البيئية وإعداد تطبيق المطورين (TikTok App Setup)](#2-المتغيرات-البيئية-وإعداد-تطبيق-المطورين-tiktok-app-setup)
3. [واجهات إدارة حسابات تيك توك للأدمن (Admin TikTok Accounts APIs)](#3-واجهات-إدارة-حسابات-تيك-توك-للأدمن-admin-tiktok-accounts-apis)
   - [1. استعراض حسابات تيك توك لمستخدم محدد (List User Accounts)](#1-استعراض-حسابات-تيك-توك-لمستخدم-محدد-list-user-accounts)
   - [2. ربط حساب يدوياً لمستخدم (Store / Link Account)](#2-ربط-حساب-يدوياً-لمستخدم-store--link-account)
   - [3. تفاصيل حساب محدد (Show Account Details)](#3-تفاصيل-حساب-محدد-show-account-details)
   - [4. تحديث الحساب والباقة والـ AI (Update Account & Quota)](#4-تحديث-الحساب-والباقة-والـ-ai-update-account--quota)
   - [5. حذف حساب تيك توك (Delete Account)](#5-حذف-حساب-تيك-توك-delete-account)
   - [6. إعادة توليد توكن التحقق (Regenerate Verify Token)](#6-إعادة-توليد-توكن-التحقق-regenerate-verify-token)
4. [هيكلية الـ Webhook وأمان التحقق (Webhook Security & Events)](#4-هيكلية-الـ-webhook-وأمان-التحقق-webhook-security--events)
5. [قاموس حقول الأدمن وقواعد العمل (Business Rules)](#5-قاموس-حقول-الأدمن-وقواعد-العمل-business-rules)

---

## 1. المصادقة وصلاحيات الأدمن (Admin Authentication)

جميع مسارات لوحة الإدارة محمية بميدلوير المصادقة والأدمن (`auth:sanctum` و `admin`). يجب إرسال توكن أدمن صالح في الـ Headers:

```http
Authorization: Bearer <ADMIN_SANCTUM_TOKEN>
Accept: application/json
Content-Type: application/json
```

---

## 2. المتغيرات البيئية وإعداد تطبيق المطورين (TikTok App Setup)

يجب ضبط القيم التالية في ملف الـ `.env` الخاص بالسيرفر:

```env
TIKTOK_CLIENT_KEY=your_tiktok_app_client_key
TIKTOK_CLIENT_SECRET=your_tiktok_app_client_secret
TIKTOK_REDIRECT_URI=https://app.smartego.org/auth/tiktok/callback
TIKTOK_VERIFY_TOKEN=your_custom_secret_verify_token
```

### الروابط المطلوبة في لوحة مطوري TikTok ([developers.tiktok.com](https://developers.tiktok.com/)):
* **Redirect URI (OAuth):**
  `https://app.smartego.org/auth/tiktok/callback`
* **Webhook URL:**
  `https://bcknd.smartego.org/api/tiktok-webhook`
* **Webhook Events للاشتراك بها:**
  - `comment.create` (أو `video.comment`)
  - `im.message.receive` (أو `direct_message`)

---

## 3. واجهات إدارة حسابات تيك توك للأدمن (Admin TikTok Accounts APIs)

### 1. استعراض حسابات تيك توك لمستخدم محدد (List User Accounts)

يعرض جميع حسابات التيك توك المرتبطة بمستخدم/مطعم معين، متضمناً **إجمالي استهلاك التوكنات (`total_tokens`)** ورصيد الرسائل وحالة الاشتراك.

* **المسار:** `GET /api/admin/users/{user}/tiktok-accounts`
* **Query Parameters (اختياري لتصفية تاريخ استهلاك التوكنات):**
  * `start_date` (`date: YYYY-MM-DD`): بداية فترة احتساب التوكنات.
  * `end_date` (`date: YYYY-MM-DD`): نهاية فترة احتساب التوكنات.

#### الاستجابة الناجحة (200 OK):
```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "user_id": 15,
      "open_id": "act_8839201948",
      "username": "smartego_store",
      "display_name": "SmartEgo Official",
      "avatar_url": "https://p16-sign-va.tiktokcdn.com/avatar.jpeg",
      "verify_token": "9a12c8b0-8f2b-4fa6-8ef3-d6c1b34a1122",
      "status": "active",
      "msg_number": 450,
      "start_date": "2026-10-01",
      "end_date": "2026-11-01",
      "ai_context": "أنت موظف خدمة عملاء في متجر سمارت إيجو...",
      "ai_file": "tiktok/ai_files/menu.txt",
      "android_link": "https://play.google.com/store/apps/details?id=com.smartego",
      "ios_link": "https://apps.apple.com/app/smartego/id123",
      "website_url": "https://smartego.org",
      "total_tokens": 12840,
      "subscription_status": true,
      "available_msgs": 450,
      "created_at": "2026-10-01 10:00:00"
    }
  ]
}
```

---

### 2. ربط حساب يدوياً لمستخدم (Store / Link Account)

يمكن للأدمن ربط حساب تيك توك يدوياً لمستخدم عن طريق توكنات الوصول والمعرف.

* **المسار:** `POST /api/admin/users/{user}/tiktok-accounts`
* **Content-Type:** `multipart/form-data` أو `application/json`

#### المدخلات (Body Parameters):
| الحقل | النوع | الإلزام | الوصف |
| :--- | :---: | :---: | :--- |
| `open_id` | `string` | **مطلوب** | معرف حساب التيك توك (`open_id`). |
| `access_token` | `string` | **مطلوب** | توكن الوصول للـ API. |
| `refresh_token` | `string` | اختياري | توكن التجديد. |
| `username` | `string` | اختياري | اسم المستخدم على تيك توك. |
| `display_name` | `string` | اختياري | الاسم المعروض للحساب. |
| `status` | `string` | اختياري | `active` (افتراضي) أو `disabled`. |
| `msg_number` | `integer` | اختياري | رصيد الرسائل الممنوح (افتراضي 0). |
| `start_date` | `date` | اختياري | تاريخ بدء الصلاحية (`YYYY-MM-DD`). |
| `end_date` | `date` | اختياري | تاريخ انتهاء الصلاحية (`YYYY-MM-DD`). |
| `ai_context` | `string` | اختياري | توجيه الذكاء الاصطناعي. |
| `ai_file` | `file` | اختياري | ملف منيو/بيانات (`.txt`, `.md`). |
| `website_url` | `string` | اختياري | رابط موقع الطلب. |
| `android_link` | `string` | اختياري | رابط تطبيق أندرويد. |
| `ios_link` | `string` | اختياري | رابط تطبيق iOS. |

#### الاستجابة الناجحة (201 Created):
```json
{
  "status": true,
  "message": "TikTok account linked successfully.",
  "data": {
    "id": 2,
    "user_id": 15,
    "open_id": "act_9918273645",
    "username": "restaurant_branch",
    "status": "active",
    "msg_number": 1000,
    "start_date": "2026-10-27",
    "end_date": "2026-11-27"
  }
}
```

---

### 3. تفاصيل حساب محدد (Show Account Details)

* **المسار:** `GET /api/admin/users/{user}/tiktok-accounts/{tiktokAccount}`

#### الاستجابة الناجحة (200 OK):
```json
{
  "status": true,
  "data": {
    "id": 1,
    "user_id": 15,
    "open_id": "act_8839201948",
    "username": "smartego_store",
    "display_name": "SmartEgo Official",
    "avatar_url": "https://...",
    "verify_token": "9a12c8b0-8f2b-4fa6-8ef3-d6c1b34a1122",
    "status": "active",
    "msg_number": 450,
    "start_date": "2026-10-01",
    "end_date": "2026-11-01",
    "ai_context": "أنت موظف خدمة عملاء...",
    "ai_file": "tiktok/ai_files/menu.txt",
    "android_link": "https://...",
    "ios_link": "https://...",
    "website_url": "https://...",
    "subscription_status": true,
    "available_msgs": 450,
    "created_at": "2026-10-01 10:00:00"
  }
}
```

---

### 4. تحديث الحساب والباقة والـ AI (Update Account & Quota)

يتيح للأدمن التحكم الكامل في الحساب: شحن رصيد الرسائل، تمديد تاريخ الصلاحية، تفعيل أو تجميد الحساب، أو تحديث سياق الـ AI.

* **المسار:** `PUT /api/admin/users/{user}/tiktok-accounts/{tiktokAccount}`
* **Content-Type:** `multipart/form-data` أو `application/json`

#### المدخلات (Body Parameters - جميعها اختيارية حسب التعديل):
| الحقل | النوع | الوصف |
| :--- | :---: | :--- |
| `status` | `string` | `active` لتفعيل الحساب أو `disabled` لتجميده وإيقاف الرد التلقائي. |
| `msg_number` | `integer` | تحديث رصيد الرسائل المتاحة للاستهلاك مباشرة. |
| `start_date` | `date` | تاريخ بداية الصلاحية (`YYYY-MM-DD`). |
| `end_date` | `date` | تاريخ نهاية الصلاحية (`YYYY-MM-DD`). |
| `ai_context` | `string` | تعديل نص البرومبت للذكاء الاصطناعي. |
| `ai_file` | `file` | رفع ملف منيو جديد. |
| `website_url` | `string` | تعديل رابط الموقع. |
| `android_link` | `string` | تعديل رابط أندرويد. |
| `ios_link` | `string` | تعديل رابط iOS. |
| `access_token` | `string` | تحديث التوكن إذا تغير يدوياً. |

#### الاستجابة الناجحة (200 OK):
```json
{
  "status": true,
  "message": "TikTok account updated successfully.",
  "data": {
    "id": 1,
    "user_id": 15,
    "open_id": "act_8839201948",
    "status": "active",
    "msg_number": 1000,
    "start_date": "2026-10-27",
    "end_date": "2026-12-27",
    "subscription_status": true,
    "available_msgs": 1000
  }
}
```

---

### 5. حذف حساب تيك توك (Delete Account)

يحذف الحساب وسجل ارتباطه من النظام.

* **المسار:** `DELETE /api/admin/users/{user}/tiktok-accounts/{tiktokAccount}`

#### الاستجابة الناجحة (200 OK):
```json
{
  "status": true,
  "message": "TikTok account deleted successfully."
}
```

---

### 6. إعادة توليد توكن التحقق (Regenerate Verify Token)

يولد `verify_token` جديد مشفر لاستخدامه في أمان الـ Webhook.

* **المسار:** `POST /api/admin/users/{user}/tiktok-accounts/{tiktokAccount}/regenerate-token`

#### الاستجابة الناجحة (200 OK):
```json
{
  "status": true,
  "message": "Verify token regenerated successfully.",
  "data": {
    "verify_token": "f47ac10b-58cc-4372-a567-0e02b2c3d479"
  }
}
```

---

## 4. هيكلية الـ Webhook وأمان التحقق (Webhook Security & Events)

الـ Webhook في تيك توك يعمل على نقطة نهاية موحدة:
`POST /api/tiktok-webhook`

### آلية المعالجة داخل السيرفر:
1. **التحقق من التوقيع (HMAC Signature):**
   السيرفر يتحقق تلقائياً من الـ Header `X-Tiktok-Signature` ومطابقته مع الـ `TIKTOK_CLIENT_SECRET`.
2. **مانع التكرار (Deduplication):**
   تيك توك يقوم أحياناً بإعادة إرسال نفس الحدث عند تأخر الاستجابة. يتم تخزين معرّف الكومنت والرسالة في الكاش لمدة 15 دقيقة لمنع تكرار الرد.
3. **منع الحلقات اللانهائية (Infinite Loops):**
   يتم تجاهل أي تعليق أو رسالة صادرة من الحساب نفسه (`open_id === commenter_id`).
4. **فحص الاشتراك والرصيد:**
   إذا كان الحساب `disabled` أو كانت الباقة منتهية أو الرصيد `msg_number <= 0`، يتم تسجيل العملية في الـ Log وتخطي الرد دون استهلاك موارد الذكاء الاصطناعي.
5. **الخصم والإحصائيات:**
   مع كل رد ناجح على تعليق أو رسالة:
   - يتم إنقاص `msg_number` بمقدار 1.
   - يتم تسجيل سطر في جدول `msg_sends` (`channel = 'tiktok'`).
   - يتم احتساب توكنات OpenAI في جدول `daily_tokens` المربوط بالحساب.

---

## 5. قاموس حقول الأدمن وقواعد العمل (Business Rules)

| الحقل | الوصف | ملاحظات الأدمن |
| :--- | :--- | :--- |
| `total_tokens` | إجمالي التوكنات المستهلكة من OpenAI | يتم تجميعها من `daily_tokens` بناءً على فترة `start_date` و `end_date` المحددة في الـ Query. |
| `available_msgs` | عدد الرسائل الفعلي المتاح للعميل | يحتسب الرصيد المباشر من `msg_number` أو من رصيد باقة الطلب (`orders.msgs`). |
| `subscription_status` | هل الرد الآلي يعمل حالياً | يعتمد على: أن يكون `status = 'active'` + التاريخ الحالي بين `start_date` و `end_date` + الرصيد $> 0$. |
| `verify_token` | توكن أمني فريد لكل حساب | يُستخدم كطبقة أمان إضافية لتمييز أحداث الحساب. |
