# توثيق الـ APIs الخاصة بتعديل الطلبات (لوحة تحكم الإدارة - Admin Panel)

هذا المستند موجه لفريق الـ **Frontend** لشرح واجهات برمجة التطبيقات (APIs) والحقول الجديدة الخاصة بإدارة قنوات واتساب والطلبات وميزة **«تعديل الطلب» (Edit Order)** من لوحة تحكم الإدارة (Super Admin Dashboard).

---

## 1. إدارة قنوات واتساب للمطاعم والمستخدمين (Admin Whats-Items)

يمكن للأدمن استعراض وإضافة وتعديل روابط القنوات التابعة لأي مستخدم، بما في ذلك رابط التعديل المخصص **`api_edit_url`**.

### 1.1 عرض قنوات مستخدم محدد (List User WhatsApp Channels)
* **المسار (Endpoint):** `GET /api/admin/users/{user_id}/whats-items`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {admin_token}
  Accept: application/json
  ```
* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "data": [
      {
        "id": 14,
        "user_id": 5,
        "phone": "+201098765432",
        "phone_number_id": "104928374829102",
        "waba_id": "938201948291039",
        "phone_status": "active",
        "order_confirmation_enabled": true,
        "ecommerce_platform": "custom",
        "website_url": "https://client-store.com",
        "store_webhook_token": "9f8e7d6c5b4a...",
        "custom_api_key": "9f8e7d6c5b4a...",
        "api_confirm_url": "https://client-store.com/api/orders/confirm",
        "api_reject_url": "https://client-store.com/api/orders/cancel",
        "api_edit_url": "https://client-store.com/api/orders/edit",
        "msg_number": 500,
        "created_at": "2026-11-01T08:00:00.000000Z"
      }
    ]
  }
  ```

---

### 1.2 إنشاء قناة واتساب لمستخدم (Create WhatsApp Channel for User)
* **المسار (Endpoint):** `POST /api/admin/users/{user_id}/whats-items`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {admin_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):**
  | الحقل | النوع | إجباري؟ | الوصف |
  |---|---|---|---|
  | `phone` | `string` | نعم | رقم الهاتف الدولي (مثال: `+201012345678`) |
  | `verified_name` | `string` | اختياري | الاسم التجاري المعتمد لدى Meta |
  | `order_confirmation_enabled` | `boolean` | اختياري | تفعيل دورة تأكيد وتعديل الطلبات |
  | `ecommerce_platform` | `string` | اختياري | `woocommerce`, `shopify`, أو `custom` |
  | `api_confirm_url` | `string` | اختياري | رابط تأكيد الطلب للـ Custom Platform |
  | `api_reject_url` | `string` | اختياري | رابط إلغاء الطلب للـ Custom Platform |
  | **`api_edit_url`** | `string` | اختياري | **[جديد] رابط تعديل الطلب للـ Custom Platform** |
  | `custom_api_key` | `string` | اختياري | مفتاح الـ API المخصص |
  | `website_url` | `string` | اختياري | رابط المتجر الإلكتروني |
  | `user_name` | `string` | اختياري | Consumer Key للووكومرس |
  | `password` | `string` | اختياري | Consumer Secret للووكومرس أو Token شوبيفاي |

* **مثال Body:**
  ```json
  {
    "phone": "+201098765432",
    "verified_name": "مطعم المشويات",
    "order_confirmation_enabled": true,
    "ecommerce_platform": "custom",
    "website_url": "https://client-store.com",
    "api_confirm_url": "https://client-store.com/api/orders/confirm",
    "api_reject_url": "https://client-store.com/api/orders/cancel",
    "api_edit_url": "https://client-store.com/api/orders/edit"
  }
  ```

---

### 1.3 تعديل قناة واتساب (Update WhatsApp Channel)
* **المسار (Endpoint):** `PUT /api/admin/users/{user_id}/whats-items/{whats_item_id}` أو `POST`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {admin_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):** يمكن إرسال الحقول المراد تحديثها فقط:
  ```json
  {
    "order_confirmation_enabled": true,
    "api_edit_url": "https://client-store.com/api/orders/v2/edit"
  }
  ```

---

## 2. متابعة الطلبات وتقارير الإدارة (Admin Orders & Reports)

عند استعراض تقارير الطلبات أو قائمة الطلبات في لوحة الإدارة (`/api/admin/orders` و `/api/admin/reports/orders`):
تحتوي عناصر الطلبات الآن على الحقول الآتية:
* **`edit_state`**:
  * `null`: طلب عادي لم يتم فتح جلسة تعديل عليه.
  * `"awaiting_edit"`: العميل طلب التعديل وجارٍ انتظار رسالته النصية بما يريد تعديله.
  * `"editing"`: جارٍ معالجة التعديل عبر ChatGPT وتطبيقه على متجر التاجر.
  * `"edited"`: تم تعديل الطلب بنجاح.
* **`edited_at`**: وقت آخر تعديل ناجح (ISO 8601).
* **`edit_items_snapshot`**: المنتجات قبل/بعد التعديل بجميع التفاصيل، الأسعار، والمقاسات.

### مثال كائن الطلب في تقارير الإدارة:
```json
{
  "id": 120,
  "user_id": 5,
  "platform": "shopify",
  "external_order_id": "5892019482",
  "order_number": "#1045",
  "customer_name": "محمد حسن",
  "customer_phone": "201123456789",
  "total_price": 680.0,
  "currency": "EGP",
  "status": "confirmed",
  "edit_state": "edited",
  "edited_at": "2026-11-10T10:14:02.000000Z",
  "confirmed_at": "2026-11-10T10:15:30.000000Z",
  "created_at": "2026-11-10T09:50:00.000000Z"
}
```

---

## 3. معلومات تقنية للوحة تحكم الإدارة (Technical Admin Notes)

### 3.1 قالب Meta WhatsApp المعتمد:
* اسم القالب في Meta Business Manager: **`order_confirmation_v2`**
* التصنيف: `UTILITY`
* اللغة: `ar`
* الأزرار (3 Quick Replies):
  1. `ORDER_APPROVE_{id}`: تأكيد الطلب
  2. `ORDER_EDIT_{id}`: تعديل الطلب
  3. `ORDER_REJECT_{id}`: إلغاء الطلب

### 3.2 استهلاك الـ Tokens في الذكاء الاصطناعي (OpenAI ChatGPT):
* يتم معالجة أوامر التعديل عبر نموذج الذكاء الاصطناعي الافتراضي المعرف في النظام (`gpt-4o-mini`).
* يتم تسجيل وتحديث استهلاك الـ Tokens يومياً في جدول `daily_tokens` تحت رقم القناة `whats_item_id` ويمكن مراقبتها من شاشة الإحصائيات واستهلاك الـ AI.

### 3.3 حماية العمليات (Fail-safes):
* إذا لم يفهم الذكاء الاصطناعي كلام العميل، لا يتم تخريب الطلب بل يرسل النظام رسالة توضيحية للعميل ويطلب منه كتابة التعديل بصيغة أوضح أو كتابة "إلغاء التعديل".
* إذا اختار العميل إلغاء التعديل، يعود الطلب لحالته السابقة فوراً ويتم تصفير `edit_state`.
