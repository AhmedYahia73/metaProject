# توثيق الـ APIs الخاصة بتعديل الطلبات (لوحة تحكم المستخدم / التاجر - User Panel)

هذا المستند موجه لفريق الـ **Frontend** لشرح جميع التعديلات والإضافات التي تمت على واجهات برمجة التطبيقات (APIs) الخاصة بقنوات واتساب والطلبات وميزة **«تعديل الطلب» (Edit Order)** عبر الذكاء الاصطناعي.

---

## 1. قنوات واتساب (WhatsApp Channels)

تمت إضافة حقل جديد باسم **`api_edit_url`** لإعدادات القناة (خاص بالمواقع المخصصة Custom Platforms) لتحديد رابط استقبال طلبات تعديل الأوردر.

### 1.1 عرض قنوات واتساب (List WhatsApp Channels)
* **المسار (Endpoint):** `GET /api/user/whats/items` (أو `GET /api/user/whats/pages`)
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Accept: application/json
  ```
* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "data": [
      {
        "id": 12,
        "phone": "+201012345678",
        "phone_status": "active",
        "phone_verified_at": "2026-11-01T12:00:00.000000Z",
        "order_confirmation_enabled": true,
        "ecommerce_platform": "custom",
        "website_url": "https://mystore.com",
        "client_id": null,
        "client_secret": null,
        "store_webhook_token": "a1b2c3d4e5f6...",
        "store_webhook_url": "https://yourdomain.com/api/webhooks/store/a1b2c3d4e5f6...",
        "api_key": "a1b2c3d4e5f6...",
        "api_confirm_url": "https://mystore.com/api/orders/confirm",
        "api_reject_url": "https://mystore.com/api/orders/cancel",
        "api_edit_url": "https://mystore.com/api/orders/edit"
      }
    ]
  }
  ```

---

### 1.2 إضافة قناة واتساب جديدة (Add WhatsApp Channel)
* **المسار (Endpoint):** `POST /api/user/whats/items`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):**
  | الحقل | النوع | إجباري؟ | الوصف |
  |---|---|---|---|
  | `phone` | `string` | نعم | رقم الهاتف مع كود الدولة (مثال: `+201012345678`) |
  | `order_confirmation_enabled` | `boolean` | اختياري | تفعيل تأكيد وتعديل الطلبات عبر واتساب (`true` أو `false`) |
  | `ecommerce_platform` | `string` | اختياري | نوع المنصة: `woocommerce` أو `shopify` أو `custom` |
  | `api_confirm_url` | `string (url)` | اختياري | رابط تأكيد الطلب للمواقع المخصصة |
  | `api_reject_url` | `string (url)` | اختياري | رابط إلغاء الطلب للمواقع المخصصة |
  | **`api_edit_url`** | `string (url)` | اختياري | **[جديد] رابط تعديل الطلب للمواقع المخصصة** |
  | `website_url` | `string` | اختياري | رابط المتجر الإلكتروني |
  | `user_name` / `client_id` | `string` | اختياري | Consumer Key للووكومرس |
  | `password` / `client_secret` | `string` | اختياري | Consumer Secret للووكومرس أو Token شوبيفاي |

* **مثال Body:**
  ```json
  {
    "phone": "+201012345678",
    "order_confirmation_enabled": true,
    "ecommerce_platform": "custom",
    "website_url": "https://mystore.com",
    "api_confirm_url": "https://mystore.com/api/orders/confirm",
    "api_reject_url": "https://mystore.com/api/orders/cancel",
    "api_edit_url": "https://mystore.com/api/orders/edit"
  }
  ```

---

### 1.3 تعديل إعدادات قناة واتساب (Update WhatsApp Channel)
* **المسار (Endpoint):** `PUT /api/user/whats/items/{whats_item_id}` أو `POST /api/user/whats/items/{whats_item_id}`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):** نفس حقول الإنشاء (يمكن إرسال الحقول المراد تعديلها فقط).
  ```json
  {
    "order_confirmation_enabled": true,
    "api_edit_url": "https://mystore.com/api/orders/edit"
  }
  ```

---

## 2. إدارة طلبات المتاجر (Ecommerce Orders)

تم إضافة حقول جديدة لمتابعة حالة التعديل بالذكاء الاصطناعي:
* **`edit_state`**: حالة التعديل الحالية:
  * `null`: الطلب في وضعه الطبيعي.
  * `"awaiting_edit"`: العميل ضغط زر «تعديل الطلب» وفي انتظار كتابة تعديله.
  * `"editing"`: جارٍ تحليل التعديل بواسطة الذكاء الاصطناعي وتنفيذه على المتجر.
  * `"edited"`: تم تعديل الطلب بنجاح.
* **`edit_items_snapshot`**: لقطة مصفوفة الأصناف المرقمة بتفاصيلها وخصائصها وقت طلب التعديل.
* **`edited_at`**: وقت وتاريخ آخر تعديل تم بنجاح (`datetime` أو `null`).

### 2.1 عرض قائمة الطلبات (List Orders)
* **المسار (Endpoint):** `GET /api/user/ecommerce-orders`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Accept: application/json
  ```
* **معاملات البحث والتصفية (Query Parameters):**
  | المعامل | النوع | الوصف |
  |---|---|---|
  | `page` | `integer` | رقم الصفحة (الافتراضي: 1) |
  | `per_page` | `integer` | عدد النتائج بالصفحة (الافتراضي: 15) |
  | `status` | `string` | تصفية حسب حالة الطلب: `pending`, `confirmed`, `cancelled` |
  | `whats_item_id` | `integer` | تصفية حسب رقم قناة الواتساب |
  | `search` | `string` | بحث برقم الطلب، اسم العميل، أو رقم الهاتف |

* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "data": {
      "current_page": 1,
      "data": [
        {
          "id": 85,
          "user_id": 1,
          "whats_item_id": 12,
          "platform": "woocommerce",
          "external_order_id": "1045",
          "order_number": "1045",
          "customer_name": "أحمد محمد",
          "customer_phone": "201012345678",
          "total_price": 450.0,
          "currency": "EGP",
          "status": "confirmed",
          "edit_state": "edited",
          "edited_at": "2026-11-10T10:15:30.000000Z",
          "confirmed_at": "2026-11-10T10:16:00.000000Z",
          "cancelled_at": null,
          "created_at": "2026-11-10T10:00:00.000000Z",
          "whats_item": {
            "id": 12,
            "phone": "+201012345678",
            "website_url": "https://mystore.com"
          }
        }
      ],
      "total": 1
    }
  }
  ```

---

### 2.2 عرض تفاصيل طلب محدد (Get Single Order Details)
* **المسار (Endpoint):** `GET /api/user/ecommerce-orders/{id}`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Accept: application/json
  ```
* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "data": {
      "id": 85,
      "platform": "woocommerce",
      "external_order_id": "1045",
      "order_number": "1045",
      "customer_name": "أحمد محمد",
      "customer_phone": "201012345678",
      "total_price": 450.0,
      "currency": "EGP",
      "status": "pending",
      "edit_state": "edited",
      "edited_at": "2026-11-10T10:15:30.000000Z",
      "edit_items_snapshot": [
        {
          "index": 1,
          "name": "بيتزا مارجريتا",
          "quantity": 3,
          "price": 150,
          "variant": null,
          "attributes": {
            "الحجم": "كبير"
          },
          "platform_item_id": 101,
          "product_id": 50
        }
      ],
      "created_at": "2026-11-10T10:00:00.000000Z"
    }
  }
  ```

---

### 2.3 إعادة إرسال رسالة التأكيد والتعديل (Resend Confirmation Template)
يُستخدم لإعادة إرسال رسالة القالب التفاعلي (المحتوي على أزرار التأكيد والتعديل والإلغاء) إلى العميل عبر واتساب.
* **المسار (Endpoint):** `POST /api/user/ecommerce-orders/{id}/resend`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Accept: application/json
  ```
* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "message": "Message sent successfully.",
    "data": {
      "success": true,
      "message": "Message sent successfully.",
      "message_id": "wamid.HBgLMj..."
    }
  }
  ```

---

## 3. المعيار الموحد للمواقع المخصصة (Custom Platform Integration)

إذا كان العميل يربط موقعاً مخصصاً (وليس Shopify أو WooCommerce)، إليك مواصفات الـ Webhook:

### 3.1 إرسال الطلب الجديد من موقع العميل لنظامنا:
* **المسار:** `POST /api/webhooks/store/{store_webhook_token}`
* **أو:** `POST /api/webhooks/custom/order` مع Header: `X-API-KEY: {api_key}`
* **Body:**
  ```json
  {
    "id": "1045",
    "customer_name": "أحمد علي",
    "customer_phone": "01012345678",
    "total_price": 300,
    "currency": "EGP",
    "items": [
      {
        "id": "item_1",
        "name": "وجبة شاورما",
        "quantity": 2,
        "price": 150,
        "attributes": {
          "الحجم": "عائلي",
          "إضافات": "تومية زيادة"
        }
      }
    ]
  }
  ```

### 3.2 إشعار التعديل المرسل من نظامنا إلى موقع العميل المخصص:
عندما يقوم العميل بتعديل طلبه عبر واتساب، يرسل نظامنا طلباً إلى رابط الـ `api_edit_url` الخاص بالعميل بالمواصفات التالية:
* **طريقة الإرسال:** `POST`
* **الترويسات (Headers):**
  ```http
  X-API-KEY: {store_api_key}
  Content-Type: application/json
  ```
* **جسم الطلب (Request Body):**
  ```json
  {
    "action": "edit_order",
    "order_id": "1045",
    "summary": "تم تعديل كمية الصنف 1 إلى 3",
    "edits": [
      {
        "action": "update_quantity",
        "item_index": 1,
        "new_quantity": 3
      }
    ],
    "items_snapshot": [
      {
        "index": 1,
        "name": "وجبة شاورما",
        "quantity": 2,
        "price": 150,
        "platform_item_id": "item_1"
      }
    ]
  }
  ```

---

## 4. دورة حياة الطلب في الفرونت إند (Order Lifecycle UI Badge Suggestions)

| قيمة `edit_state` | قيمة `status` | المسمى المقترح في الـ UI | اللون المقترح |
|---|---|---|---|
| `null` | `pending` | في انتظار الرد | أصفر / رمادي (Pending) |
| `"awaiting_edit"` | `pending` | العميل يعدل الطلب ✏️ | أزرق فاتح (Editing in progress) |
| `"edited"` | `pending` | تم التعديل (في انتظار التأكيد) ✅ | برتقالي / أزرق داكن (Edited) |
| أي قيمة | `confirmed` | تم التأكيد وجارٍ التجهيز 🛍️ | أخضر (Confirmed) |
| أي قيمة | `cancelled` | ملغي ❌ | أحمر (Cancelled) |
