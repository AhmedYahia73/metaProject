<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فحص Reverb Real-Time - Mostafa Gad</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.3.0/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
</head>
<body>

    <div style="text-align: center; margin-top: 50px; font-family: sans-serif;">
        <h1 style="color: #2d3748;">📡 صفحة فحص البث اللحظي (Reverb)</h1>
        <p style="color: #4a5568; font-size: 18px;">افتح الـ <strong>Console (F12)</strong> لمراقبة حالة الاتصال والطلبات الجديدة!</p>
        
        <div id="status" style="display: inline-block; padding: 10px 20px; background: #feebc8; color: #c05621; border-radius: 5px; font-weight: bold;">
            جاري الاتصال بسيرفر Reverb...
        </div>
    </div>

    <script>
        // 1. إعداد واجهة Laravel Echo
        const wsHost = window.location.hostname;
        const isHttps = window.location.protocol === 'https:';

        window.Echo = new window.Echo({
            broadcaster: 'reverb',
            key: '{{ env('REVERB_APP_KEY') }}',
            wsHost: wsHost,
            wsPort: isHttps ? 443 : 8080,
            wssPort: isHttps ? 443 : 8080,
            forceTLS: isHttps,
            enabledTransports: ['ws', 'wss'],
        });

        const statusDiv = document.getElementById('status');

        // تحديث الواجهة عند نجاح الاتصال
        window.Echo.connector.pusher.connection.bind('connected', function() {
            console.log('✅ Connected to Reverb Successfully via WSS!');
            statusDiv.innerText = '✅ تم الاتصال بنجاح بسيرفر Reverb!';
            statusDiv.style.background = '#c6f6d5';
            statusDiv.style.color = '#22543d';
        });

        // رصد أخطاء الاتصال إن وجدت
        window.Echo.connector.pusher.connection.bind('error', function(err) {
            console.error('❌ Connection Error:', err);
            statusDiv.innerText = '❌ فشل الاتصال بسيرفر Reverb';
            statusDiv.style.background = '#fed7d7';
            statusDiv.style.color = '#742a2a';
        });

        // 2. الاستماع للقناة newOrder والحدث NewOrderEvent
        window.Echo.channel('newOrder')
            .listen('.NewOrderEvent', (data) => {
                console.log('🎯 وصلت نوتيفيكيشن جديدة لايف!!');
                console.log('📦 Data Received:', data);
                alert('تم استلام طلب جديد برقم: ' + data.order_id);
            });
    </script>
</body>
</html>