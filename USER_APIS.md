# توثيق واجهات المستخدم (User APIs Documentation)

هذا المستند موجه لفريق الـ **Frontend** والـ Mobile App لشرح جميع واجهات الـ API الخاصة بالمستخدم العادي (`role: user`) التي تم إضافتها في هذه المحادثة (إدارة العملاء، إرسال رسائل القوالب الجماعية، واستعلام حالة باقات التمبلت).

جميع الـ Endpoints تتطلب ترويسة المصادقة:
```http
Authorization: Bearer {user_token}
Accept: application/json
```

---

## 1. إدارة العملاء (Clients Management - CRUD)

تتيح هذه الواجهات للمستخدم إدارة عملائه الخاصين به فقط (`user_id = auth.user.id`).

---

### 1.1 استعراض العملاء (List Clients)
* **المسار (Endpoint):** `GET /api/user/clients`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Accept: application/json
  ```
* **معاملات الاستعلام (Query Parameters):**
  | المعامل | النوع | إجباري؟ | الوصف |
  |---|---|---|---|
  | `search` | `string` | اختياري | البحث في اسم العميل (`name`) أو رقم الواتساب (`whats_number`) |
  | `page` | `integer` | اختياري | رقم الصفحة (الافتراضي: 1) |
  | `per_page` | `integer` | اختياري | عدد العناصر في كل صفحة (الافتراضي: 15) |
  | `all` | `boolean` | اختياري | إذا كانت `true` يرجع جميع العملاء دفعة واحدة دون Pagination |

* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "data": {
      "current_page": 1,
      "data": [
        {
          "id": 1,
          "user_id": 5,
          "name": "أحمد محمد",
          "whats_number": "201012345678",
          "created_at": "2026-11-23T07:31:27.000000Z",
          "updated_at": "2026-11-23T07:31:27.000000Z"
        }
      ],
      "total": 1,
      "per_page": 15
    }
  }
  ```

---

### 1.2 إضافة عميل جديد (Create Client)
* **المسار (Endpoint):** `POST /api/user/clients`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):**
  | الحقل | النوع | إجباري؟ | الوصف |
  |---|---|---|---|
  | `name` | `string` | نعم | اسم العميل (بحد أقصى 255 حرف) |
  | `whats_number` | `string` | نعم | رقم هاتف واتساب العميل (بحد أقصى 50 حرف) |

* **مثال Body:**
  ```json
  {
    "name": "محمود حسن",
    "whats_number": "201099887766"
  }
  ```

* **الاستجابة الناجحة (Success Response 201 Created):**
  ```json
  {
    "status": true,
    "message": "Client created successfully.",
    "data": {
      "id": 2,
      "user_id": 5,
      "name": "محمود حسن",
      "whats_number": "201099887766",
      "created_at": "2026-11-23T07:35:00.000000Z",
      "updated_at": "2026-11-23T07:35:00.000000Z"
    }
  }
  ```

* **استجابة الخطأ في التحقق (422 Unprocessable Entity):**
  ```json
  {
    "message": "The name field is required. (and 1 more error)",
    "errors": {
      "name": ["The name field is required."],
      "whats_number": ["The whats number field is required."]
    }
  }
  ```

---

### 1.3 عرض تفاصيل عميل محدد (Show Client)
* **المسار (Endpoint):** `GET /api/user/clients/{id}`
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
      "id": 2,
      "user_id": 5,
      "name": "محمود حسن",
      "whats_number": "201099887766",
      "created_at": "2026-11-23T07:35:00.000000Z",
      "updated_at": "2026-11-23T07:35:00.000000Z"
    }
  }
  ```
* **استجابة عدم الصلاحية (403 Forbidden):**
  *(في حال محاولة الوصول لعميل لا يتبع المستخدم الحالي)*
  ```json
  {
    "status": false,
    "message": "Unauthorized."
  }
  ```

---

### 1.4 تعديل بيانات عميل (Update Client)
* **المسار (Endpoint):** `PUT /api/user/clients/{id}` أو `PATCH /api/user/clients/{id}`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):**
  | الحقل | النوع | إجباري؟ | الوصف |
  |---|---|---|---|
  | `name` | `string` | اختياري | الاسم الجديد للعميل |
  | `whats_number` | `string` | اختياري | رقم الواتساب الجديد |

* **مثال Body:**
  ```json
  {
    "name": "محمود حسن المعدل",
    "whats_number": "201011223344"
  }
  ```

* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "message": "Client updated successfully.",
    "data": {
      "id": 2,
      "user_id": 5,
      "name": "محمود حسن المعدل",
      "whats_number": "201011223344",
      "created_at": "2026-11-23T07:35:00.000000Z",
      "updated_at": "2026-11-23T07:40:00.000000Z"
    }
  }
  ```

---

### 1.5 حذف عميل (Delete Client)
* **المسار (Endpoint):** `DELETE /api/user/clients/{id}`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Accept: application/json
  ```
* **الاستجابة الناجحة (Success Response 200 OK):**
  ```json
  {
    "status": true,
    "message": "Client deleted successfully."
  }
  ```

---

## 2. إرسال الرسائل الجماعية للعملاء (Send Bulk Template Messages)

تتيح هذه الواجهة اختيار مجموعة من العملاء وإرسال رسالة قالب معتمدة في Meta في الـ **Background** مع نظام دفعات (20 عميل كل 5 دقائق).

### 2.1 إرسال رسالة لعملاء محددين (Dispatch Bulk Messages)
* **المسار (Endpoint):** `POST /api/user/clients/send-messages`
* **الترويسات (Headers):**
  ```http
  Authorization: Bearer {user_token}
  Content-Type: application/json
  Accept: application/json
  ```
* **جسم الطلب (Request Body):**
  | الحقل | النوع | إجباري؟ | الوصف |
  |---|---|---|---|
  | `whats_item_id` | `integer` | نعم | معرف رقم الواتساب المسجل للمستخدم (`WhatsItem.id`) |
  | `client_ids` | `array` | نعم | مصفوفة بمعرفات العملاء المطلوب الإرسال إليهم |
  | `client_ids.*` | `integer` | نعم | معرف كل عميل (يجب أن يكون من عملاء المستخدم) |
  | `msg` | `string` | نعم | نص الرسالة المراد إرسالها للعملاء |

* **شروط التحقق (Business Validation Rules):**
  1. يجب أن يكون رقم الواتساب تابعاً للمستخدم الحالي.
  2. يجب أن تكون كل معرفات العملاء في `client_ids` تابعة للمستخدم الحالي.
  3. يجب أن يكون تاريخ اليوم بين أو يساوي `t_start_date` و `t_end_date` لرقم الواتساب.
  4. يجب أن يكون رصيد رسائل القوالب المتاح `t_msg_number >= عدد العملاء المحددين`.

* **مثال Body:**
  ```json
  {
    "whats_item_id": 3,
    "client_ids": [1, 2, 5, 8],
    "msg": "عروض الجمعة البيضاء! خصم 20% على جميع الأصناف اليوم فقط."
  }
  ```

* **الاستجابة الناجحة عند قبول الإرسال وجدولته (Success Response 202 Accepted):**
  ```json
  {
    "status": true,
    "message": "Bulk message queued successfully for 4 client(s) across 1 batch(es).",
    "data": {
      "total_clients": 4,
      "batches_count": 1,
      "whats_item_id": 3,
      "available_t_msg_number": 500
    }
  }
  ```

* **آلية المعالجة في الخلفية (Background Execution & Deductions):**
  - يتم تقسيم العملاء إلى دفعات كل دفعة بحد أقصى **20 عميلاً**.
  - الدفعة الأولى تُرسل فوراً، والدفعة الثانية بعد 5 دقائق، والثالثة بعد 10 دقائق وهكذا.
  - يتم تسجيل **Log** تفصيلي في كل خطوة (بدء المعالجة، فحص الرقم، إرسال كل عميل).
  - **يتم خصم الرسالة من `t_msg_number` فقط عند نجاح الإرسال الفعلي لكل عميل**.
  - في حال فشل الإرسال لأي عميل، يتم تسجيل Log بالخطأ وتفاصيل الرفض **ولا يُخصم أي شيء من الرصيد**.

* **استجابات الخطأ المحتملة:**
  - **عدم كفاية الرصيد (422 Unprocessable Entity):**
    ```json
    {
      "status": false,
      "message": "Insufficient template message balance. Available: 2, Required: 4."
    }
    ```
  - **انتهاء صلاحية تاريخ باقة التمبلت (422 Unprocessable Entity):**
    ```json
    {
      "status": false,
      "message": "The template package has expired or is not yet active for this WhatsApp number."
    }
    ```
  - **عدم وجود باقة تمبلت مفعلة برقم الهاتف (422 Unprocessable Entity):**
    ```json
    {
      "status": false,
      "message": "WhatsApp item does not have an active template package date range."
    }
    ```
  - **العميل أو رقم الهاتف لا يتبع المستخدم (403 Forbidden):**
    ```json
    {
      "status": false,
      "message": "WhatsApp item not found or does not belong to you."
    }
    ```

---

## 3. استعلام حالة باقات التمبلت لقنوات واتساب (Template Package Status)

واجهة مخصصة لعرض أرقام الواتساب التابعة للمستخدم مع بيان ما إذا كانت باقة التمبلت مفعلة حالياً ورصيدها والتواريخ.

### 3.1 استعراض حالة الباقة لكل رقم واتساب (List WhatsApp Numbers Template Status)
* **المسار (Endpoint):** `GET /api/user/whats/template-status-items`
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
        "id": 3,
        "phone": "+201012345678",
        "is_package_active": true,
        "t_msg_number": 490,
        "t_start_date": "2026-11-20",
        "t_end_date": "2026-12-20",
        "phone_status": "active"
      },
      {
        "id": 4,
        "phone": "+201098765432",
        "is_package_active": false,
        "t_msg_number": 0,
        "t_start_date": null,
        "t_end_date": null,
        "phone_status": "verified"
      }
    ]
  }
  ```
* **شرح الحقول:**
  - `is_package_active`: المفتاح الرئيسي والوحيد الذي يحدد ما إذا كانت باقة التمبلت سارية المفعول حالياً (تاريخ اليوم بين `t_start_date` و `t_end_date` ورصيد `t_msg_number > 0`).
  - `t_msg_number`: رصيد رسائل القوالب المتبقي للرقم.
  - `t_start_date`: تاريخ بداية صلاحية باقة القوالب.
  - `t_end_date`: تاريخ نهاية صلاحية باقة القوالب.
