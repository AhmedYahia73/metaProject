# توثيق واجهات الإدارة (Admin APIs Documentation)

هذا المستند موجه لفريق الـ **Frontend** لشرح واجهات برمجة التطبيقات (APIs) والحقول الخاصة بلوحة تحكم الإدارة (`role: admin`) التي تم إضافتها أو تعديلها في هذه المحادثة (تفعيل باقات التمبلت مباشرة للأرقام، اعتماد الطلبات ذات باقات التمبلت، وإنشاء وتعديل الباقات بحقل `is_template`).

جميع الـ Endpoints تتطلب ترويسة المصادقة:
```http
Authorization: Bearer {admin_token}
Accept: application/json
```

---

## 1. تفعيل باقة مباشرة لرقم واتساب (Direct Package Activation for WhatsApp)

تتيح هذه الواجهة للأدمن تفعيل أي باقة (سواء كانت باقة تمبلت أو باقة عادية) مباشرة لأي رقم واتساب تابع لأي مستخدم دون المرور بدورة الطلب والدفع.

### 1.1 تفعيل باقة لرقم واتساب (Activate Package for WhatsApp Item)
* **المسار (Endpoint):** `POST /api/admin/users/{user_id}/whats-items/{whats_item_id}/activate-package`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {admin_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):**
  | الحقل | النوع | إجباري؟ | الوصف |
  |---|---|---|---|
  | `package_id` | `integer` | نعم | معرف الباقة المراد تفعيلها (`packages.id`) |
  | `is_template` | `boolean` | اختياري | لتحديد إذا ما كان التفعيل لباقة تمبلت. إن لم يُمرر، يعتمد على قيمة `package.is_template` المسجلة بالباقة |

* **سلوك التفعيل (Activation Behavior):**
  - **إذا كانت الباقة تمبلت (`is_template: true`):**
    - يتم ضبط تاريخ البداية `t_start_date` = تاريخ اليوم.
    - يتم ضبط تاريخ النهاية `t_end_date` = تاريخ اليوم + عدد شهور الباقة (`package.months`).
    - يتم إضافة عدد رسائل الباقة إلى رصيد رسائل التمبلت `t_msg_number = t_msg_number + package.msg_number`.
    - يتم تحديث `phone_status` إلى `active`.
    - **لا يتم المساس** بحقول الرسائل العادية (`msg_number`, `start_date`, `end_date`).
  - **إذا كانت الباقة عادية (`is_template: false`):**
    - يتم تفعيل الحقول العادية (`start_date`, `end_date`, `msg_number`).

* **مثال Body:**
  ```json
  {
    "package_id": 4
  }
  ```

* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "message": "Package activated successfully for WhatsApp number.",
    "data": {
      "id": 14,
      "user_id": 5,
      "phone": "+201012345678",
      "phone_status": "active",
      "msg_number": 500,
      "start_date": "2026-11-01",
      "end_date": "2026-12-01",
      "t_msg_number": 1000,
      "t_start_date": "2026-11-23",
      "t_end_date": "2026-12-23",
      "updated_at": "2026-11-23T08:00:00.000000Z"
    }
  }
  ```

* **استجابة الخطأ (404 Not Found):**
  *(في حال عدم تبعية رقم الواتساب للمستخدم المحدد في المسار)*
  ```json
  {
    "message": "WhatsApp item not found for this user."
  }
  ```

---

## 2. اعتماد طلبات باقات التمبلت (Order Approval)

عند قيام المشترك بطلب باقة WhatsApp والدفع أو عند اعتماد الأدمن لطلب اشتراك معلق يدوياً.

### 2.1 اعتماد الطلب يدوياً من الأدمن (Approve Pending Order)
* **المسار (Endpoint):** `POST /api/admin/orders/{order_id}/approve`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {admin_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **سلوك النظام عند اعتماد طلب واتساب:**
  - إذا كان الطلب يحمل علامة **`order.is_template == true`**:
    - يتم تحديث `t_start_date` = تاريخ اليوم.
    - يتم تحديث `t_end_date` = تاريخ اليوم + عدد شهور الباقة.
    - يتم زيادة رصيد التمبلت `t_msg_number` بعدد رسائل الباقة.
    - حالة الهاتف تصبح `phone_status = active`.
  - إذا كان `order.is_template == false`:
    - يتم تحديث حقول الرسائل العادية (`start_date`, `end_date`, `msg_number`).

* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "message": "Order #45 approved successfully.",
    "data": {
      "order_id": 45,
      "status": "approved",
      "channel": "whatsapp",
      "from": "2026-11-23",
      "to": "2026-12-23",
      "activation": {
        "whats_item_id": 14,
        "phone": "+201012345678",
        "whatsapp_msgs_added": 1000,
        "is_template": true,
        "msg_number": 0,
        "t_msg_number": 1000,
        "start_date": null,
        "end_date": null,
        "t_start_date": "2026-11-23",
        "t_end_date": "2026-12-23"
      }
    }
  }
  ```

---

## 3. إدارة الباقات بحقل التمبلت (Package Management with `is_template`)

تم دعم حقل `is_template` في الـ CRUD الخاص بالباقات لتمكين الأدمن من تصنيف الباقة كباقة رسائل قوالب واتساب.

### 3.1 إنشاء باقة جديدة (Create Package)
* **المسار (Endpoint):** `POST /api/admin/packages`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {admin_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):**
  | الحقل | النوع | إجباري؟ | الوصف |
  |---|---|---|---|
  | `name` | `object` | نعم | اسم الباقة باللغات (يحتوي `ar` و `en`) |
  | `type` | `string` | نعم | نوع القناة: `whats`, `face`, `all`, `instagram`, `telegram`, `tiktok` |
  | **`is_template`** | `boolean` | اختياري | **هل هذه الباقة مخصصة لرسائل القوالب (Templates)؟ الافتراضي: `false`** |
  | `msg_number` | `integer` | نعم | عدد الرسائل المتاحة في الباقة |
  | `price` | `numeric` | نعم | السعر الأساسي للباقة |
  | `months` | `integer` | نعم | مدة صلاحية الباقة بالشهور (مثال: 1 أو 3 أو 12) |
  | `discount_id` | `integer` | اختياري | معرف الخصم إن وجد |
  | `tax_id` | `integer` | اختياري | معرف الضريبة إن وجد |

* **مثال Body:**
  ```json
  {
    "name": {
      "ar": "باقة رسائل التمبلت الشهرية",
      "en": "Monthly WhatsApp Templates Package"
    },
    "type": "whats",
    "is_template": true,
    "msg_number": 2000,
    "price": 350.00,
    "months": 1
  }
  ```

* **الاستجابة الناجحة (Success Response 201 Created):**
  ```json
  {
    "status": true,
    "message": "Package created successfully.",
    "data": {
      "id": 8,
      "name": {
        "ar": "باقة رسائل التمبلت الشهرية",
        "en": "Monthly WhatsApp Templates Package"
      },
      "type": "whats",
      "is_template": true,
      "msg_number": 2000,
      "price": "350.00",
      "months": 1,
      "discount_id": null,
      "tax_id": null,
      "created_at": "2026-11-23T08:15:00.000000Z",
      "updated_at": "2026-11-23T08:15:00.000000Z"
    }
  }
  ```

---

### 3.2 تعديل باقة (Update Package)
* **المسار (Endpoint):** `PUT /api/admin/packages/{id}`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {admin_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):**
  يدعم تعديل أي من حقول الباقة بما فيها:
  ```json
  {
    "is_template": true,
    "msg_number": 2500,
    "price": 400.00
  }
  ```

* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "message": "Package updated successfully.",
    "data": {
      "id": 8,
      "type": "whats",
      "is_template": true,
      "msg_number": 2500,
      "price": "400.00"
    }
  }
  ```
