(function () {
    'use strict';
    const root = document.getElementById('usdt-cashier');
    if (!root) return;
    const dictionaries = {
        'zh-cn': {title:'USDT 支付',instruction:'请使用 TRON（TRC20）网络转入精确金额。',copyAmount:'复制金额',copyAddress:'复制地址',address:'收款地址',notice:'仅限 USDT-TRC20。请保留全部小数位，二维码只包含收款地址。',order:'订单号',time:'付款剩余时间',txidLabel:'已转账但还未确认？填写 TXID 核验',verify:'核验转账',return:'返回订单',pending:'等待转账及链上确认',settling:'付款时间已结束，正在确认截止前的转账。请勿继续付款。',timeout:'订单已超时，请勿再向该订单转账。',paid:'付款已确认，正在返回订单。',checking:'正在核验链上转账…',error:'暂未确认匹配转账，系统会继续查询。',badTxid:'请填写 64 位十六进制 TXID。',copied:'已复制',copyFailed:'请手动选择并复制。',expired:'付款时间已结束'},
        'zh-tw': {title:'USDT 付款',instruction:'請使用 TRON（TRC20）網路轉入精確金額。',copyAmount:'複製金額',copyAddress:'複製地址',address:'收款地址',notice:'僅限 USDT-TRC20。請保留全部小數位，QR 碼只包含收款地址。',order:'訂單編號',time:'付款剩餘時間',txidLabel:'已轉帳但尚未確認？填寫 TXID 驗證',verify:'驗證轉帳',return:'返回訂單',pending:'等待轉帳及鏈上確認',settling:'付款時間已結束，正在確認截止前的轉帳。請勿繼續付款。',timeout:'訂單已逾時，請勿再向此訂單轉帳。',paid:'付款已確認，正在返回訂單。',checking:'正在驗證鏈上轉帳…',error:'尚未確認匹配轉帳，系統會繼續查詢。',badTxid:'請填寫 64 位十六進位 TXID。',copied:'已複製',copyFailed:'請手動選取並複製。',expired:'付款時間已結束'},
        en: {title:'Pay with USDT',instruction:'Send the exact amount using the TRON (TRC20) network.',copyAmount:'Copy amount',copyAddress:'Copy address',address:'Receiving address',notice:'USDT-TRC20 only. Keep every decimal digit. The QR code contains the address only.',order:'Order number',time:'Time left to pay',txidLabel:'Transferred but still waiting? Enter the TXID to verify.',verify:'Verify transfer',return:'Return to order',pending:'Waiting for transfer and chain confirmation',settling:'Payment time has ended. Checking transfers sent before the deadline. Do not send another payment.',timeout:'This order has expired. Do not transfer funds for this order.',paid:'Payment confirmed. Returning to your order.',checking:'Checking the chain transfer…',error:'A matching transfer is not confirmed yet. Checks will continue.',badTxid:'Enter a 64-character hexadecimal TXID.',copied:'Copied',copyFailed:'Select and copy manually.',expired:'Payment time has ended'},
        ru: {title:'Оплата USDT',instruction:'Отправьте точную сумму через сеть TRON (TRC20).',copyAmount:'Копировать сумму',copyAddress:'Копировать адрес',address:'Адрес получателя',notice:'Только USDT-TRC20. Сохраните все цифры после запятой. QR-код содержит только адрес.',order:'Номер заказа',time:'Осталось для оплаты',txidLabel:'Перевод ещё не подтверждён? Укажите TXID для проверки.',verify:'Проверить перевод',return:'Вернуться к заказу',pending:'Ожидание перевода и подтверждения сети',settling:'Время оплаты истекло. Проверяем переводы до срока оплаты. Не отправляйте новый платёж.',timeout:'Срок заказа истёк. Не переводите средства для этого заказа.',paid:'Оплата подтверждена. Возвращаемся к заказу.',checking:'Проверяем перевод в сети…',error:'Подходящий перевод пока не подтверждён. Проверка продолжится.',badTxid:'Введите TXID из 64 шестнадцатеричных символов.',copied:'Скопировано',copyFailed:'Выделите и скопируйте вручную.',expired:'Время оплаты истекло'},
        vi: {title:'Thanh toán USDT',instruction:'Chuyển đúng số tiền qua mạng TRON (TRC20).',copyAmount:'Sao chép số tiền',copyAddress:'Sao chép địa chỉ',address:'Địa chỉ nhận',notice:'Chỉ USDT-TRC20. Giữ nguyên mọi chữ số thập phân. Mã QR chỉ chứa địa chỉ.',order:'Mã đơn hàng',time:'Thời gian thanh toán còn lại',txidLabel:'Đã chuyển nhưng chưa xác nhận? Nhập TXID để kiểm tra.',verify:'Kiểm tra giao dịch',return:'Quay lại đơn hàng',pending:'Đang chờ giao dịch và xác nhận trên chuỗi',settling:'Đã hết thời gian thanh toán. Đang kiểm tra giao dịch gửi trước hạn. Không chuyển thêm tiền.',timeout:'Đơn hàng đã hết hạn. Không chuyển tiền cho đơn này.',paid:'Đã xác nhận thanh toán. Đang quay lại đơn hàng.',checking:'Đang kiểm tra giao dịch trên chuỗi…',error:'Chưa xác nhận được giao dịch phù hợp. Hệ thống sẽ tiếp tục kiểm tra.',badTxid:'Nhập TXID gồm 64 ký tự thập lục phân.',copied:'Đã sao chép',copyFailed:'Vui lòng chọn và sao chép thủ công.',expired:'Đã hết thời gian thanh toán'}
    };
    const locale = Object.prototype.hasOwnProperty.call(dictionaries, root.dataset.locale) ? root.dataset.locale : 'zh-cn';
    const words = dictionaries[locale];
    document.documentElement.lang = locale;
    document.title = words.title + ' · TRC20';
    root.querySelectorAll('[data-usdt-text]').forEach(el => { el.textContent = words[el.dataset.usdtText] || ''; });
    const status = document.getElementById('usdt-status');
    const txidInput = document.getElementById('usdt-txid');
    const verifyButton = document.getElementById('usdt-verify');
    const expiresAt = Number(root.dataset.expiresAt) * 1000;
    const settlementExpiresAt = Number(root.dataset.settlementExpiresAt) * 1000;
    const pollInterval = Math.min(60, Math.max(5, Number(root.dataset.pollInterval) || 8)) * 1000;
    let timer = null;
    let stopped = false;
    let inFlight = false;
    let currentStatus = 'pending';
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const applyTheme = () => {
        let appearance = document.body.dataset.storeDefaultAppearance || 'system';
        try {
            const saved = localStorage.getItem('store-appearance');
            if (['system', 'light', 'gray', 'dark'].includes(saved)) appearance = saved;
        } catch (_) {}
        document.documentElement.dataset.storeTheme = ['light', 'gray', 'dark'].includes(appearance) ? appearance : (media.matches ? 'dark' : 'light');
    };
    applyTheme();
    if (typeof media.addEventListener === 'function') media.addEventListener('change', applyTheme);
    window.addEventListener('storage', applyTheme);
    let returnUrl = '';
    try {
        const target = new URL(root.dataset.returnUrl || '', location.origin);
        if (target.origin === location.origin && ['http:', 'https:'].includes(target.protocol)) returnUrl = target.pathname + target.search + target.hash;
    } catch (_) {}
    const returnLink = document.getElementById('usdt-return');
    if (returnUrl) returnLink.href = returnUrl;
    else returnLink.hidden = true;
    if (window.jQuery && typeof window.jQuery.fn.qrcode === 'function') {
        window.jQuery('#usdt-qr').qrcode({render:'canvas',width:200,height:200,text:root.dataset.address || ''});
    }
    const updateStatus = value => {
        if (!['pending', 'settling', 'timeout', 'paid'].includes(value)) return;
        currentStatus = value;
        status.textContent = words[value];
        root.dataset.status = value;
        if (value === 'timeout' || value === 'paid') {
            stopped = true;
            clearTimeout(timer);
            verifyButton.disabled = true;
            txidInput.disabled = true;
        }
        if (value === 'paid' && returnUrl) setTimeout(() => location.assign(returnUrl), 900);
    };
    const countdown = () => {
        const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        document.getElementById('usdt-countdown').textContent = remaining > 0
            ? String(Math.floor(remaining / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0')
            : words.expired;
        if (currentStatus !== 'paid' && Date.now() >= settlementExpiresAt) updateStatus('timeout');
        else if (currentStatus === 'pending' && remaining === 0) updateStatus('settling');
    };
    updateStatus('pending');
    countdown();
    const countdownTimer = setInterval(countdown, 1000);
    async function check(txid = '') {
        if (stopped || inFlight) return;
        inFlight = true;
        clearTimeout(timer);
        verifyButton.disabled = true;
        if (txid) status.textContent = words.checking;
        const payload = new URLSearchParams({trade_no:root.dataset.tradeNo || '',poll_token:root.dataset.pollToken || ''});
        if (txid) payload.set('txid', txid);
        try {
            const response = await fetch('/user/api/usdt/check?lang=' + encodeURIComponent(locale), {
                method:'POST',credentials:'same-origin',cache:'no-store',
                headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
                body:payload.toString()
            });
            if (!response.ok) throw new Error('request');
            const result = await response.json();
            if (String(result.code) !== '200' && String(result.code) !== '1') throw new Error('request');
            if (!result.data || !['pending','settling','timeout','paid'].includes(result.data.status)) throw new Error('status');
            updateStatus(result.data.status);
        } catch (_) {
            if (!stopped) status.textContent = words.error;
        } finally {
            inFlight = false;
            verifyButton.disabled = stopped;
            if (!stopped) timer = setTimeout(() => check(), pollInterval);
        }
    }
    document.getElementById('usdt-verify-form').addEventListener('submit', event => {
        event.preventDefault();
        const txid = txidInput.value.trim().toLowerCase();
        if (!/^[a-f0-9]{64}$/.test(txid)) { status.textContent = words.badTxid; return; }
        check(txid);
    });
    root.querySelectorAll('[data-usdt-copy]').forEach(button => button.addEventListener('click', async () => {
        const value = button.dataset.usdtCopy === 'address' ? root.dataset.address : root.dataset.usdtAmount;
        try {
            await navigator.clipboard.writeText(value || '');
            button.textContent = words.copied;
            setTimeout(() => { button.textContent = words[button.dataset.usdtCopy === 'address' ? 'copyAddress' : 'copyAmount']; }, 1400);
        } catch (_) { status.textContent = words.copyFailed; }
    }));
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); clearInterval(countdownTimer); });
    check();
})();
