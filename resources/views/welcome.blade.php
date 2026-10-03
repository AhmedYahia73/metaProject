<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فحص البث اللحظي (Reverb Real-Time Monitor) — Instagram & Messenger & WhatsApp</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pusher/8.3.0/pusher.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --border-color: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --insta-grad: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
            --accent-green: #10b981;
            --accent-blue: #3b82f6;
            --accent-yellow: #f59e0b;
            --accent-red: #ef4444;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-primary);
            padding: 24px;
            min-height: 100vh;
        }

        .container {
            max-width: 1050px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 24px;
        }

        h1 {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 15px;
        }

        #status-bar {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 20px;
            border-radius: 9999px;
            font-weight: bold;
            font-size: 14px;
            margin: 16px auto;
            background: #451a03;
            color: #fed7aa;
            border: 1px solid #7c2d12;
            transition: all 0.3s ease;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: currentColor;
            display: inline-block;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
        }

        .tab-btn {
            background: transparent;
            border: 1px solid transparent;
            color: var(--text-secondary);
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .tab-btn.active {
            background: var(--card-bg);
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        .tab-btn.insta-tab.active {
            border-color: #e1306c;
            color: #ff758c;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
        }

        .card-header {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .input-group label {
            display: block;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 6px;
            font-weight: 600;
        }

        .input-group input, .input-group textarea {
            width: 100%;
            background: #0b1120;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }

        .input-group input:focus, .input-group textarea:focus {
            outline: none;
            border-color: var(--accent-blue);
        }

        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.2s, transform 0.1s;
        }

        .btn:hover { opacity: 0.9; }
        .btn:active { transform: scale(0.98); }

        .btn-insta {
            background: var(--insta-grad);
            color: #fff;
        }

        .btn-blue {
            background: #2563eb;
            color: #fff;
        }

        .btn-green {
            background: #059669;
            color: #fff;
        }

        .btn-ghost {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        .btn-ghost:hover {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.05);
        }

        /* Console Log View */
        .terminal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .terminal-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #log {
            background: #090d16;
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 16px;
            font-family: "JetBrains Mono", "Fira Code", monospace;
            font-size: 13px;
            line-height: 1.6;
            max-height: 480px;
            overflow-y: auto;
            text-align: left;
            direction: ltr;
        }

        .log-entry {
            margin: 6px 0;
            padding: 6px 10px;
            border-radius: 6px;
            word-break: break-word;
            border-left: 3px solid transparent;
        }

        .log-entry.info {
            background: rgba(59, 130, 246, 0.08);
            border-left-color: var(--accent-blue);
            color: #93c5fd;
        }

        .log-entry.message {
            background: rgba(16, 185, 129, 0.12);
            border-left-color: var(--accent-green);
            color: #6ee7b7;
        }

        .log-entry.typing {
            background: rgba(245, 158, 11, 0.12);
            border-left-color: var(--accent-yellow);
            color: #fde047;
        }

        .log-entry.error {
            background: rgba(239, 68, 68, 0.15);
            border-left-color: var(--accent-red);
            color: #fca5a5;
        }

        .log-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            margin-right: 6px;
            text-transform: uppercase;
        }

        .badge-insta { background: #be185d; color: #fff; }
        .badge-wa { background: #047857; color: #fff; }
        .badge-fb { background: #1d4ed8; color: #fff; }

        .active-channels {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .channel-tag {
            background: #0f172a;
            border: 1px solid var(--border-color);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-family: monospace;
            color: #a5b4fc;
        }
    </style>
</head>
<body>

    <div class="container">
        <header>
            <h1>📡 شاشة فحص البث اللحظي (Real-Time Monitor)</h1>
            <p class="subtitle">مراقبة أحداث Webhooks و Reverb WebSockets لحظياً بدون تحديث الصفحة</p>
            <div id="status-bar">
                <span class="status-dot"></span>
                <span id="status-text">جاري الاتصال بسيرفر Reverb...</span>
            </div>
        </header>

        <!-- Navigation Tabs -->
        <div class="tabs">
            <button class="tab-btn insta-tab active" onclick="switchTab('insta')">📸 فحص Instagram</button>
            <button class="tab-btn" onclick="switchTab('messenger')">💬 فحص Messenger</button>
            <button class="tab-btn" onclick="switchTab('whatsapp')">📱 فحص WhatsApp</button>
        </div>

        <!-- ── TAB 1: INSTAGRAM ─────────────────────────────────── -->
        <div id="tab-insta" class="card">
            <div class="card-header">
                <span>📸 أدوات فحص Instagram Webhook & Realtime</span>
                <span style="font-size:12px; color:#34d399; font-weight:normal;">✅ القناة العامة <code style="color:#fbcfe8;">userInsta_</code> مفعلة تلقائياً</span>
            </div>

            <div class="form-grid">
                <div class="input-group">
                    <label>Instagram Account ID (المعرف الخاص بالحساب):</label>
                    <input id="instaAccountId" type="text" placeholder="مثال: 17841400000000000 (أو 0 للاختيار التلقائي)" value="0">
                </div>
                <div class="input-group">
                    <label>Instagram Sender ID (معرف العميل IGSID):</label>
                    <input id="instaSenderId" type="text" placeholder="مثال: 9876543210123" value="test_user_777">
                </div>
            </div>

            <div class="input-group" style="margin-bottom:12px;">
                <label>نص رسالة العميل للتجربة:</label>
                <input id="instaTestMessage" type="text" placeholder="اكتب رسالة تجريبية هنا..." value="مرحبا، بكام عرض اليوم وهل متاح توصيل؟">
            </div>

            <div class="btn-group">
                <button class="btn btn-insta" onclick="simulateInstagramWebhook()">
                    🚀 إرسال تجربة فورية إلى Webhook (Simulate)
                </button>
                <button class="btn btn-ghost" onclick="listenCustomInstaChannel()">
                    🎧 استماع لقناة مخصصة للعميل
                </button>
            </div>
        </div>

        <!-- ── TAB 2: MESSENGER ─────────────────────────────────── -->
        <div id="tab-messenger" class="card" style="display:none;">
            <div class="card-header">
                <span>💬 أدوات فحص Messenger Webhook</span>
            </div>
            <div class="form-grid">
                <div class="input-group">
                    <label>Page ID:</label>
                    <input id="pageId" type="text" placeholder="106565280821724" value="106565280821724">
                </div>
                <div class="input-group">
                    <label>Sender ID (PSID):</label>
                    <input id="senderId" type="text" placeholder="29269086176028063" value="29269086176028063">
                </div>
            </div>
            <div class="btn-group">
                <button class="btn btn-blue" onclick="listenMessenger()">
                    🎧 استمع Messenger
                </button>
            </div>
        </div>

        <!-- ── TAB 3: WHATSAPP ──────────────────────────────────── -->
        <div id="tab-whatsapp" class="card" style="display:none;">
            <div class="card-header">
                <span>📱 أدوات فحص WhatsApp Webhook</span>
            </div>
            <div class="form-grid">
                <div class="input-group">
                    <label>رقم WhatsApp (مثال: 201xxxxxxxxx):</label>
                    <input id="waPhone" type="text" placeholder="201206610346" value="201206610346">
                </div>
            </div>
            <div class="btn-group">
                <button class="btn btn-green" onclick="listenWhatsApp()">
                    🎧 استمع WhatsApp
                </button>
            </div>
        </div>

        <!-- ── ACTIVE CHANNELS ──────────────────────────────────── -->
        <div style="margin-bottom: 12px;">
            <span style="font-size:12px; color:var(--text-secondary);">القنوات النشطة حالياً في المراقبة:</span>
            <div id="activeChannelsList" class="activeChannels">
                <span class="channel-tag">userInsta_ (Global Instagram)</span>
                <span class="channel-tag">userChat_ (Global Typing)</span>
            </div>
        </div>

        <!-- ── LIVE CONSOLE LOG ─────────────────────────────────── -->
        <div class="card" style="padding:16px;">
            <div class="terminal-header">
                <div class="terminal-title">
                    <span>⚡ سجل الأحداث اللحظي (Live Stream)</span>
                </div>
                <button class="btn btn-ghost" style="padding:4px 10px; font-size:12px;" onclick="clearLogs()">
                    🗑️ مسح السجل
                </button>
            </div>
            <div id="log">
                <div class="log-entry info">[--:--:--] في انتظار الأحداث اللحظية...</div>
            </div>
        </div>
    </div>

    <script>
        const statusText = document.getElementById('status-text');
        const statusBar  = document.getElementById('status-bar');
        const logDiv     = document.getElementById('log');

        function addLog(msg, type = 'info', badge = null) {
            const el = document.createElement('div');
            el.className = `log-entry ${type}`;
            const time = new Date().toLocaleTimeString();
            let badgeHtml = '';
            if (badge) {
                badgeHtml = `<span class="log-badge ${badge.cls}">${badge.txt}</span>`;
            }
            el.innerHTML = `[${time}] ${badgeHtml}${msg}`;
            logDiv.prepend(el);
        }

        function clearLogs() {
            logDiv.innerHTML = '<div class="log-entry info">[--:--:--] تم مسح السجل. في انتظار أحداث جديدة...</div>';
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('tab-insta').style.display = 'none';
            document.getElementById('tab-messenger').style.display = 'none';
            document.getElementById('tab-whatsapp').style.display = 'none';

            if (tab === 'insta') {
                document.getElementById('tab-insta').style.display = 'block';
                event.target.classList.add('active');
            } else if (tab === 'messenger') {
                document.getElementById('tab-messenger').style.display = 'block';
                event.target.classList.add('active');
            } else if (tab === 'whatsapp') {
                document.getElementById('tab-whatsapp').style.display = 'block';
                event.target.classList.add('active');
            }
        }

        function registerChannelTag(name) {
            const list = document.getElementById('activeChannelsList');
            const tags = list.getElementsByClassName('channel-tag');
            for (let t of tags) {
                if (t.innerText.includes(name)) return;
            }
            const tag = document.createElement('span');
            tag.className = 'channel-tag';
            tag.innerText = name;
            list.appendChild(tag);
        }

        // ── 1. إعداد Echo والاتصال بـ Reverb ────────────────────────
        const wsHost = window.location.hostname;
        const isHttps = window.location.protocol === 'https:';

        window.Echo = new window.Echo({
            broadcaster: 'reverb',
            key: '{{ env("REVERB_APP_KEY") }}',
            wsHost: wsHost,
            wsPort: isHttps ? 443 : 8080,
            wssPort: isHttps ? 443 : 8080,
            forceTLS: isHttps,
            enabledTransports: ['ws', 'wss'],
        });

        window.Echo.connector.pusher.connection.bind('connected', function () {
            console.log('✅ Connected to Reverb!');
            statusText.innerText = '✅ متصل بسيرفر Reverb بنجاح ومستعد لاستقبال الأحداث!';
            statusBar.style.background = '#064e3b';
            statusBar.style.borderColor = '#047857';
            statusBar.style.color = '#a7f3d0';
            addLog('✅ تم الاتصال بنجاح بسيرفر البث اللحظي Reverb', 'info');

            // بدء الاستماع التلقائي للقنوات العامة بمجرد الاتصال
            setupGlobalListeners();
        });

        window.Echo.connector.pusher.connection.bind('error', function (err) {
            console.error('❌ Reverb Connection Error:', err);
            statusText.innerText = '❌ فشل الاتصال بسيرفر Reverb';
            statusBar.style.background = '#450a0a';
            statusBar.style.borderColor = '#991b1b';
            statusBar.style.color = '#fecaca';
            addLog('❌ خطأ في الاتصال بسيرفر Reverb: ' + JSON.stringify(err), 'error');
        });

        // ── 2. الاستماع التلقائي لقنوات Instagram العامة ─────────────
        function setupGlobalListeners() {
            // القناة العامة لجميع رسائل Instagram
            window.Echo.channel('userInsta_')
                .listen('.UserChatEvent', (data) => {
                    console.log('💬 [INSTAGRAM GLOBAL] رسالة جديدة:', data);
                    const sender = data.instagram_sender_id || 'مستخدم';
                    const msg = data.message || '';
                    const senderType = data.sender_type || (data.is_admin ? 'bot' : 'customer');
                    addLog(
                        `<strong>${senderType === 'bot' ? '🤖 رد الـ Bot' : '👤 عميل'} (${sender}):</strong> "${msg}"`,
                        'message',
                        { cls: 'badge-insta', txt: 'Instagram' }
                    );
                });

            // الاستماع لحالة جاري الكتابة (Typing indicator)
            window.Echo.channel('userChat_')
                .listen('.TypingEvent', (data) => {
                    if (data.channel === 'instagram') {
                        console.log('✍️ [INSTAGRAM] جاري الكتابة:', data);
                        addLog('✍️ Bot الانستجرام يكتب رداً الآن...', 'typing', { cls: 'badge-insta', txt: 'Typing' });
                    }
                });

            addLog('🎧 تم تفعيل الاستماع التلقائي للقناة العامة: userInsta_', 'info');
        }

        // ── 3. الاستماع لقناة Instagram محددة بالعميل ──────────────
        function listenCustomInstaChannel() {
            const accountId = document.getElementById('instaAccountId').value.trim();
            const senderId  = document.getElementById('instaSenderId').value.trim();

            if (!senderId) {
                alert('يرجى إدخال Sender ID على الأقل');
                return;
            }

            const channel1 = `userInsta_${senderId}`;
            window.Echo.channel(channel1)
                .listen('.UserChatEvent', (data) => {
                    addLog(`💬 [قناة محددة] رسالة من ${data.instagram_sender_id}: "${data.message}"`, 'message', { cls: 'badge-insta', txt: 'Instagram' });
                });
            registerChannelTag(channel1);

            if (accountId && accountId !== '0') {
                const channel2 = `userInsta_${senderId}_${accountId}`;
                window.Echo.channel(channel2)
                    .listen('.UserChatEvent', (data) => {
                        addLog(`💬 [قناة مزدوجة] رسالة: "${data.message}"`, 'message', { cls: 'badge-insta', txt: 'Instagram' });
                    });
                registerChannelTag(channel2);
            }

            addLog(`🎧 تم تفعيل الاستماع للعميل: ${senderId}`, 'info');
        }

        // ── 4. زر التجربة الفورية: إرسال محاكاة لـ instagram_web_hook ─
        async function simulateInstagramWebhook() {
            const accountId = document.getElementById('instaAccountId').value.trim() || '0';
            const senderId  = document.getElementById('instaSenderId').value.trim() || 'test_user_777';
            const message   = document.getElementById('instaTestMessage').value.trim() || 'تجربة فحص';

            addLog(`🚀 جاري إرسال طلب محاكاة الـ Webhook إلى /api/instagram-webhook ...`, 'info');

            const payload = {
                object: 'instagram',
                entry: [
                    {
                        id: accountId,
                        time: Date.now(),
                        messaging: [
                            {
                                sender: { id: senderId },
                                recipient: { id: accountId },
                                message: {
                                    mid: 'mid.test.' + Date.now(),
                                    text: message
                                }
                            }
                        ]
                    }
                ]
            };

            try {
                const response = await fetch('/api/instagram-webhook', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();
                console.log('Webhook Response:', data);

                if (response.ok) {
                    addLog(`✅ تم تسليم الـ Webhook بنجاح (الحالة: ${data.status || 'OK'}). راقب وصول الـ Realtime بالأسفل!`, 'info');
                    if (data.reply) {
                        addLog(`🤖 رد الـ AI المستلم من الـ Webhook: "${data.reply}"`, 'message', { cls: 'badge-insta', txt: 'AI Reply' });
                    }
                } else {
                    addLog(`⚠️ استجاب الـ Webhook بكود خطأ: ${response.status} — ${JSON.stringify(data)}`, 'error');
                }
            } catch (err) {
                console.error('Fetch Error:', err);
                addLog(`❌ فشل الاتصال برابط الـ Webhook: ${err.message}`, 'error');
            }
        }

        // ── 5. استماع Messenger ─────────────────────────────────────
        function listenMessenger() {
            const pageId   = document.getElementById('pageId').value.trim();
            const senderId = document.getElementById('senderId').value.trim();
            if (!pageId || !senderId) { alert('ادخل Page ID و Sender ID'); return; }

            const channelName = `userChat_${senderId}_${pageId}`;
            window.Echo.channel(channelName)
                .listen('.UserChatEvent', (data) => {
                    console.log('💬 [Messenger] رسالة جديدة:', data);
                    addLog(`💬 [Messenger] رسالة: "${data.message}"`, 'message', { cls: 'badge-fb', txt: 'Messenger' });
                })
                .listen('.TypingEvent', (data) => {
                    addLog(`✍️ Bot ماسنجر يكتب...`, 'typing', { cls: 'badge-fb', txt: 'Typing' });
                });

            registerChannelTag(channelName);
            addLog(`🎧 يستمع على Messenger channel: ${channelName}`, 'info');
        }

        // ── 6. استماع WhatsApp ──────────────────────────────────────
        function listenWhatsApp() {
            const phone = document.getElementById('waPhone').value.trim();
            if (!phone) { alert('ادخل رقم الهاتف'); return; }

            const channelName = `userWhats_${phone}`;
            window.Echo.channel(channelName)
                .listen('.UserChatEvent', (data) => {
                    console.log('💬 [WhatsApp] رسالة جديدة:', data);
                    addLog(`💬 [WA] رسالة من ${data.phone}: "${data.message}"`, 'message', { cls: 'badge-wa', txt: 'WhatsApp' });
                })
                .listen('.TypingEvent', (data) => {
                    addLog(`✍️ Bot واتساب يكتب...`, 'typing', { cls: 'badge-wa', txt: 'Typing' });
                });

            registerChannelTag(channelName);
            addLog(`🎧 يستمع على WhatsApp channel: ${channelName}`, 'info');
        }
    </script>
</body>
</html>