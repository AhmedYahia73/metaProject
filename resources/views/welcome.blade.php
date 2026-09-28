<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فحص Reverb Real-Time</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.3.0/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
    <style>
        body { font-family: sans-serif; text-align: center; margin-top: 40px; background: #f7fafc; }
        h1 { color: #2d3748; }
        #status { display: inline-block; padding: 10px 20px; border-radius: 6px; font-weight: bold; margin: 10px; }
        #log { text-align: left; direction: ltr; max-width: 800px; margin: 20px auto;
               background: #1a202c; color: #68d391; padding: 20px; border-radius: 8px;
               font-family: monospace; font-size: 14px; max-height: 400px; overflow-y: auto; }
        .log-entry { margin: 4px 0; border-bottom: 1px solid #2d3748; padding: 4px 0; }
        .typing { color: #f6e05e; }
        .message { color: #68d391; }
        .error { color: #fc8181; }
        .info { color: #90cdf4; }
    </style>
</head>
<body>

    <h1>📡 صفحة فحص البث اللحظي (Reverb)</h1>
    <p style="color:#4a5568; font-size:17px;">افتح الـ <strong>Console (F12)</strong> لمراقبة الأحداث</p>

    <div id="status" style="background:#feebc8; color:#c05621;">
        جاري الاتصال بسيرفر Reverb...
    </div>

    <br>

    {{-- ── أدوات الفحص ──────────────────────── --}}
    <div style="margin: 20px;">
        <label style="font-weight:bold;">رقم WhatsApp (مثال: 201xxxxxxxxx):</label><br>
        <input id="waPhone" type="text" placeholder="201xxxxxxxxx" style="padding:8px;width:250px;margin:5px;">
        <button onclick="listenWhatsApp()">استمع WhatsApp</button>
    </div>

    <div style="margin: 20px;">
        <label style="font-weight:bold;">Messenger — Page ID:</label><br>
        <input id="pageId" type="text" placeholder="106565280821724" style="padding:8px;width:250px;margin:5px;">
        <label style="font-weight:bold;">Sender ID (PSID):</label><br>
        <input id="senderId" type="text" placeholder="29269086176028063" style="padding:8px;width:250px;margin:5px;">
        <button onclick="listenMessenger()">استمع Messenger</button>
    </div>

    <div id="log"><em class="info">في انتظار الأحداث...</em></div>

    <script>
        const statusDiv = document.getElementById('status');
        const logDiv    = document.getElementById('log');

        function addLog(msg, type = 'info') {
            const el = document.createElement('div');
            el.className = `log-entry ${type}`;
            el.innerHTML = `[${new Date().toLocaleTimeString()}] ${msg}`;
            logDiv.prepend(el);
        }

        // ── إعداد Echo ─────────────────────────────────────────────
        const wsHost  = window.location.hostname;
        const isHttps = window.location.protocol === 'https:';

        window.Echo = new window.Echo({
            broadcaster: 'reverb',
            key: '{{ env("REVERB_APP_KEY") }}',
            wsHost:   wsHost,
            wsPort:   isHttps ? 443 : 8080,
            wssPort:  isHttps ? 443 : 8080,
            forceTLS: isHttps,
            enabledTransports: ['ws', 'wss'],
        });

        window.Echo.connector.pusher.connection.bind('connected', function () {
            console.log('✅ Connected to Reverb!');
            statusDiv.innerText = '✅ متصل بسيرفر Reverb بنجاح!';
            statusDiv.style.background = '#c6f6d5';
            statusDiv.style.color      = '#22543d';
            addLog('✅ متصل بـ Reverb', 'info');
        });

        window.Echo.connector.pusher.connection.bind('error', function (err) {
            console.error('❌ Reverb Error:', err);
            statusDiv.innerText = '❌ فشل الاتصال بسيرفر Reverb';
            statusDiv.style.background = '#fed7d7';
            statusDiv.style.color      = '#742a2a';
            addLog('❌ خطأ في الاتصال: ' + JSON.stringify(err), 'error');
        });

        // ── استماع WhatsApp ─────────────────────────────────────────
        function listenWhatsApp() {
            const phone = document.getElementById('waPhone').value.trim();
            if (!phone) { alert('ادخل رقم الهاتف'); return; }

            // قناة الرسائل
            window.Echo.channel(`userWhats_${phone}`)
                .listen('.UserChatEvent', (data) => {
                    console.log('💬 [WhatsApp] رسالة جديدة:', data);
                    addLog(`💬 [WA] رسالة من ${data.phone}: "${data.message}"`, 'message');
                })
                .listen('.TypingEvent', (data) => {
                    console.log('✍️ [WhatsApp] Typing:', data);
                    addLog(`✍️ [WA] Bot يكتب...`, 'typing');
                });

            // القناة العامة (backup)
            window.Echo.channel('userWhats_')
                .listen('.UserChatEvent', (data) => {
                    if (data.phone === phone) {
                        addLog(`💬 [WA-global] رسالة: "${data.message}"`, 'message');
                    }
                })
                .listen('.TypingEvent', (data) => {
                    if (data.phone === phone) {
                        addLog(`✍️ [WA-global] Bot يكتب...`, 'typing');
                    }
                });

            addLog(`🎧 يستمع على WhatsApp channel: userWhats_${phone}`, 'info');
        }

        // ── استماع Messenger ─────────────────────────────────────────
        function listenMessenger() {
            const pageId   = document.getElementById('pageId').value.trim();
            const senderId = document.getElementById('senderId').value.trim();
            if (!pageId || !senderId) { alert('ادخل Page ID و Sender ID'); return; }

            const channelName = `userChat_29269086176028063_106565280821724`;

            window.Echo.channel(channelName)
                .listen('.UserChatEvent', (data) => {
                    console.log('💬 [Messenger] رسالة جديدة:', data);
                    addLog(`💬 [Messenger] رسالة: "${data.message}"`, 'message');
                })
                .listen('.TypingEvent', (data) => {
                    console.log('✍️ [Messenger] Typing:', data);
                    addLog(`✍️ [Messenger] Bot يكتب...`, 'typing');
                });

            addLog(`🎧 يستمع على Messenger channel: ${channelName}`, 'info');
        }
    </script>
</body>
</html>