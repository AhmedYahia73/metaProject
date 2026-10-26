# دليل استخدام WhatsApp Embedded Signup APIs

هذا الدليل يوضح بالتفصيل آلية عمل وطريقة استخدام واجهات برمجة التطبيقات (APIs) الخاصة بـ **WhatsApp Embedded Signup** في المشروع، والموجودة في المتحكم:
[`app/Http/Controllers/api/user/EmbeddedSignupController.php`](file:///e:/backend/metaProject/app/Http/Controllers/api/user/EmbeddedSignupController.php)
والمعتمدة على الخدمة:
[`app/Services/EmbeddedSignupService.php`](file:///e:/backend/metaProject/app/Services/EmbeddedSignupService.php)

---

## الفهرس
1. [نظرة عامة على الميزة](#نظرة-عامة)
2. [المتطلبات الأساسية وإعدادات .env](#المتطلبات-الأساسية)
3. [تفاصيل الـ Endpoints](#تفاصيل-الـ-endpoints)
   - [1. إتمام عملية الربط (Complete Signup)](#1-إتمام-عملية-الربط-complete-signup)
   - [2. فحص الأرقام المتاحة (Inspect Code)](#2-فحص-الأرقام-المتاحة-inspect-code)
   - [3. إعادة تفعيل الويب هوك (Resubscribe Webhook)](#3-إعادة-تفعيل-الويب-هوك-resubscribe-webhook)
   - [4. تسجيل وتفعيل الرقم على واتساب (Register Phone with PIN)](#4-تسجيل-وتفعيل-الرقم-على-واتساب-register-phone-with-pin)
4. [سيناريوهات الاستخدام ودورة العمل (Workflows)](#سيناريوهات-الاستخدام)
   - [السيناريو الأول: الربط المباشر بخطوة واحدة](#السيناريو-الأول-الربط-المباشر-بخطوة-واحدة)
   - [السيناريو الثاني: الفحص واختيار الرقم من قائمة](#السيناريو-الثاني-الفحص-واختيار-الرقم-من-قائمة)
   - [السيناريو الثالث: صيانة واستعادة الويب هوك](#السيناريو-الثالث-صيانة-واستعادة-الويب-هوك)
5. [أكواد الواجهة الأمامية (Frontend Integration)](#أكواد-الواجهة-الأمامية-frontend-integration)

---

## نظرة عامة

ميزة **WhatsApp Embedded Signup** مقدمة من شركة Meta، تسمح للمستخدمين (أصحاب المتاجر والأنشطة) بربط أرقام واتساب الخاصة بهم مباشرة مع منصتك من خلال نافذة منبثقة (Popup) عبر Facebook JavaScript SDK دون أي تدخل يدوي معقد.

### أهم فوائدها:
- **تخطي حد الـ 20 رقماً:** الحسابات تُنشأ في مساحة العميل الخاصة (WABA خاص به) بدلاً من مشاركة حساب WABA رئيسي واحد للمنصة.
- **ربط سلس وفوري:** استخراج التوكن واشتراك الويب هوك وتفعيل الرد الآلي في ثوانٍ معدودة.

---

## المتطلبات الأساسية

تأكد من تهيئة المتغيرات التالية في ملف `.env` الخاص بالباك إند:
```env
META_APP_ID=your_meta_app_id
META_APP_SECRET=your_meta_app_secret
META_GRAPH_VERSION=v21.0
```

جميع الـ Endpoints التالية محمية بـ Middleware:
```php
middleware(['auth:sanctum', 'user'])
```
لذلك يجب إرسال توكن المستخدم المسجل في الـ Header:
```http
Authorization: Bearer <USER_SANCTUM_TOKEN>
Accept: application/json
```

---

## تفاصيل الـ Endpoints

---

### 1. إتمام عملية الربط (Complete Signup)

يقوم هذا الـ Endpoint باستقبال الكود المؤقت (`code`) الناتج من نافذة Meta، وتبديله بـ Access Token، ثم جلب بيانات الرقم وحسابه، والاشتراك في الويب هوك، وحفظ السجل في جدول `whats_items`.

* **المسار:** `POST /api/whats/embedded-signup`
* **المتحكم:** `EmbeddedSignupController@completeSignup`
* **نوع المحتوى:** `multipart/form-data` (في حال إرسال ملف للذكاء الاصطناعي) أو `application/json`.

#### المدخلات (Request Payload):

| الحقل | النوع | الإلزامية | الوصف |
| :--- | :--- | :--- | :--- |
| `code` | `string` | **إجباري** | الكود القصير المؤقت العائد من Facebook Login SDK |
| `phone_number_id` | `string` | اختياري | معرّف رقم الهاتف المطلوب ربطه (إذا كان لدى العميل أكثر من رقم في حسابه) |
| `android_link` | `string` | اختياري | رابط تطبيق المتجر على Google Play |
| `ios_link` | `string` | اختياري | رابط تطبيق المتجر على App Store |
| `website_url` | `string` | اختياري | رابط موقع المتجر الإلكتروني |
| `ai_context` | `string` | اختياري | البرومبت والتعليمات الموجهة لبوت الذكاء الاصطناعي |
| `ai_file` | `file` | اختياري | ملف نصي (`.txt`, `.md`) يحتوي على داتا تدريبية للبوت (بحد أقصى 2MB) |

#### ما يحدث في الخلفية:
1. إرسال الكود لـ Meta: `GET /oauth/access_token` لتبديله بـ `customer_token`.
2. استدعاء `GET /debug_token` لاستخراج الـ WABA الممنوحة وصلاحية `whatsapp_business_management`.
3. جلب تفاصيل رقم الهاتف (`verified_name`, `display_phone_number`, `status`).
4. فحص ما إذا كان الرقم مسجلاً سابقاً للمستخدم:
   - **إذا وُجد:** يتم تحديث بياناته وإعادة ربط الويب هوك تلقائياً.
   - **إذا لم يوجد:** يشترك التطبيق في الويب هوك: `POST /{waba_id}/subscribed_apps`، ثم تسجيل الرقم في Cloud API إن لم يكن متصلاً، وإنشاء سجل `WhatsItem` جديد بحالة `active`.

#### نموذج الاستجابة بنجاح (201 Created):
```json
{
  "status": true,
  "message": "WhatsApp Business Account connected successfully. Auto-reply webhook is now active.",
  "data": {
    "id": 12,
    "user_id": 3,
    "phone": "+201012345678",
    "phone_number_id": "100293847561029",
    "waba_id": "200394857162534",
    "customer_waba_id": "200394857162534",
    "token_type": "customer",
    "phone_status": "active",
    "ai_context": "أنت مساعد خدمة عملاء لمتجرنا...",
    "created_at": "2026-10-26T10:00:00.000000Z"
  },
  "meta": {
    "waba_id": "200394857162534",
    "phone_number_id": "100293847561029",
    "webhook_subscribed": true,
    "all_wabas": [
      {
        "waba_id": "200394857162534",
        "phone_number_ids": ["100293847561029"]
      }
    ]
  }
}
```

---

### 2. فحص الأرقام المتاحة (Inspect Code)

يُستخدم هذا الـ Endpoint لمعاينة واستكشاف الحسابات وأرقام الهواتف التابعة للعميل **قبل** إتمام الحفظ، وهو مناسب جداً لبناء واجهة تتيح للعميل اختيار الرقم الذي يفضله إذا كان يملك أكثر من رقم.

* **المسار:** `POST /api/whats/embedded-signup/inspect`
* **المتحكم:** `EmbeddedSignupController@inspectCode`
* **نوع المحتوى:** `application/json`

#### المدخلات (Request Payload):
```json
{
  "code": "AQD4hK9...short_lived_meta_code..."
}
```

#### ما يحدث في الخلفية:
1. تبديل الـ `code` بـ Access Token.
2. استخراج كافة الـ WABAs التابعة للتوكن.
3. المرور على كل رقم في كل WABA واستخراج بياناته (`display_phone_number`, `verified_name`, `status`).
4. إرجاع النتائج دون حفظ أي بيانات في قاعدة البيانات.

#### نموذج الاستجابة بنجاح (200 OK):
```json
{
  "status": true,
  "access_token": "EAAXz...",
  "wabas": [
    {
      "waba_id": "200394857162534",
      "phones": [
        {
          "id": "100293847561029",
          "display_phone_number": "+20 101 234 5678",
          "verified_name": "الفرع الرئيسي",
          "status": "CONNECTED"
        },
        {
          "id": "100293847561030",
          "display_phone_number": "+20 109 876 5432",
          "verified_name": "الدعم الفني",
          "status": "CONNECTED"
        }
      ]
    }
  ]
}
```

---

### 3. إعادة تفعيل الويب هوك (Resubscribe Webhook)

يُستخدم لإعادة ربط تطبيقك بـ Webhook حساب العميل في حال توقف استقبال الرسائل أو انقطاع اشتراك الويب هوك من جهة Meta.

* **المسار:** `POST /api/whats/items/{whatsItem}/resubscribe-webhook`
* **المتحكم:** `EmbeddedSignupController@resubscribeWebhook`
* **المعاملات (URL Parameter):**
  - `{whatsItem}`: معرّف السجل (ID) في جدول `whats_items` التابع للمستخدم.
* **المدخلات:** لا يحتاج Request Body.

#### شروط العمل:
1. يجب أن يكون السجل ملكاً للمستخدم الحالي (`user_id`).
2. يجب أن يكون الرقم قد تم ربطه مسبقاً عبر الـ Embedded Signup (`token_type === 'customer'`).

#### نموذج الاستجابة بنجاح (200 OK):
```json
{
  "status": true,
  "message": "Webhook re-subscribed successfully. Your auto-reply is now active."
}
```

---

### 4. تسجيل وتفعيل الرقم على واتساب (Register Phone with PIN)

يُستخدم هذا الـ Endpoint لتسجيل الرقم الموثق على WhatsApp Cloud API باستخدام كود PIN مكون من 6 أرقام (Two-Step Verification). وهو الذي ينقل حالة الرقم في سيرفرات Meta من **`PENDING`** إلى **`CONNECTED`**، ويقوم تلقائياً بتفعيل الرقم في النظام وإعطائه:
- `phone_status: active`
- `start_date: اليوم (now)`
- `end_date: بعد شهر (now + 1 month)`
- `msg_number: 1000 رسالة`

* **المسار للمستخدم:** `POST /api/whats/items/{whatsItem}/register`
* **المسار للأدمن:** `POST /api/admin/users/{user}/whats-items/{whatsItem}/register`
* **نوع المحتوى:** `application/json`

#### المدخلات (Request Payload):
```json
{
  "pin": "123456"
}
```

#### نموذج الاستجابة بنجاح (200 OK):
```json
{
  "status": true,
  "message": "Phone number registered and connected to WhatsApp successfully!",
  "data": {
    "id": 14,
    "user_id": 4,
    "phone": "+20 11 43469626",
    "phone_number_id": "1409267442271757",
    "phone_status": "active",
    "phone_verified_at": "2026-10-26T14:26:00.000000Z",
    "start_date": "2026-10-26",
    "end_date": "2026-11-26",
    "msg_number": 1000
  }
}
```

---

## سيناريوهات الاستخدام

### السيناريو الأول: الربط المباشر بخطوة واحدة
هذا هو الخيار الأسهل والأكثر شيوعاً:
1. العميل يضغط على زر "ربط واتساب".
2. تفتح نافذة Facebook Login المنبثقة.
3. بعد إكمال الخطوات، يرجع `code`.
4. الواجهة ترسل الـ `code` مباشرة إلى `POST /api/whats/embedded-signup`.
5. الباك إند يختار الرقم الأول تلقائياً، يربط الويب هوك ويفعّل البوت فوراً.

---

### السيناريو الثاني: الفحص واختيار الرقم من قائمة
مناسب للعملاء الذين يمتلكون أرقاماً متعددة:
1. العميل يكمل نافذة الـ Popup ويرجع `code`.
2. الواجهة ترسل الكود أولاً إلى `POST /api/whats/embedded-signup/inspect`.
3. تعرض الواجهة قائمة الأرقام المتاحة للمستخدم ليختار الرقم المطلوب.
4. يختار المستخدم الرقم ويضغط "تأكيد".
5. ترسل الواجهة الـ `code` مع الـ `phone_number_id` المختار إلى `POST /api/whats/embedded-signup`.

---

### السيناريو الثالث: صيانة واستعادة الويب هوك
في لوحة تحكم المستخدم بجوار كل رقم متصل:
- تضع زراً بعنوان **"إعادة مزامنة الرد الآلي / فحص الاتصال"**.
- عند الضغط عليه، يُستدعى `POST /api/whats/items/{id}/resubscribe-webhook`.
- يؤكد هذا الاستدعاء اشتراك المنصة في أحداث الواتساب الخاصة برقم العميل لضمان عمل الرد التلقائي بدون مشاكل.

---

## أكواد الواجهة الأمامية (Frontend Integration)

### 1. إعداد الـ Facebook SDK
في ملف `index.html` أو المكون الأساسي:
```html
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
```

تهيئة الـ SDK:
```javascript
window.fbAsyncInit = function () {
  window.FB.init({
    appId: 'YOUR_META_APP_ID',
    autoLogAppEvents: true,
    xfbml: true,
    version: 'v21.0'
  });
};
```

---

### 2. فتح نافذة الربط (Triggering the Popup)
```javascript
function connectWhatsApp() {
  window.FB.login(function (response) {
    if (response.authResponse && response.authResponse.code) {
      const code = response.authResponse.code;
      console.log('Authorization Code:', code);

      // تنفيذ الربط المباشر
      completeSignup(code);
    } else {
      console.warn('User canceled or did not fully authorize the app.');
    }
  }, {
    config_id: 'YOUR_META_CONFIGURATION_ID', // معرّف تهيئة WhatsApp Embedded Signup من Meta App Dashboard
    response_type: 'code',
    override_default_response_type: true,
    extras: {
      setup: {}
    }
  });
}
```

---

### 3. إرسال الكود إلى API الباك إند
```javascript
async function completeSignup(code, selectedPhoneId = null) {
  const token = localStorage.getItem('auth_token');

  const formData = new FormData();
  formData.append('code', code);
  
  if (selectedPhoneId) {
    formData.append('phone_number_id', selectedPhoneId);
  }

  formData.append('ai_context', 'أنت مساعد ذكي لمتجرنا، قم بالترحيب بالعملاء وتوضيح مواعيد العمل.');
  formData.append('website_url', 'https://example.com');
  
  // إذا كان هناك ملف سياق للـ AI:
  // const fileInput = document.getElementById('aiFileInput');
  // if (fileInput.files[0]) {
  //   formData.append('ai_file', fileInput.files[0]);
  // }

  try {
    const res = await fetch('/api/whats/embedded-signup', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      },
      body: formData
    });

    const data = await res.json();
    if (data.status) {
      alert('تم ربط رقم الواتساب وتفعيل الويب هوك بنجاح!');
      console.log('WhatsItem:', data.data);
    } else {
      alert('فشل الربط: ' + data.message);
    }
  } catch (error) {
    console.error('Error connecting WhatsApp:', error);
  }
}
```

---

### 4. استدعاء إعادة الاشتراك في الويب هوك
```javascript
async function resubscribeWebhook(whatsItemId) {
  const token = localStorage.getItem('auth_token');

  try {
    const res = await fetch(`/api/whats/items/${whatsItemId}/resubscribe-webhook`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    });

    const data = await res.json();
    if (data.status) {
      alert(data.message);
    } else {
      alert('خطأ: ' + data.message);
    }
  } catch (error) {
    console.error('Webhook resubscribe error:', error);
  }
}
```
