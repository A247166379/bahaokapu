(function () {
    'use strict';
    const dictionary = {
        '优惠券':['優惠券','Coupon','Купон'],
        '即将售罄':['即將售罄', 'Low stock', 'Осталось мало'], '一般':['一般', 'Available', 'В наличии'], '充足':['充足', 'In stock', 'В наличии'], '非常多':['非常多', 'Plenty in stock', 'Большой запас'], '所剩无几':['所剩無幾', 'Few left', 'Осталось мало'], '数量有限':['數量有限', 'Limited stock', 'Ограниченный запас'], '现货充足':['現貨充足', 'In stock', 'В наличии'], '库存爆棚':['庫存充足', 'Plenty in stock', 'Большой запас'], 
        '加载中…':['載入中…','Loading…','Загрузка…'], '请求失败，请稍后重试':['請求失敗，請稍後重試','Request failed. Please try again.','Ошибка запроса. Повторите попытку позже.'],
        '价格暂不可用':['價格暫時不可用','Price unavailable','Цена недоступна'], '选择支付方式':['選擇支付方式','Select a payment method','Выберите способ оплаты'], '暂无可用支付方式':['暫無可用付款方式','No payment methods available','Нет доступных способов оплаты'],
        '立即购买':['立即購買','Buy now','Купить'], '库存不足':['庫存不足','Not enough stock','Недостаточно товара'], '库存':['庫存','Stock','Наличие'], '已售罄':['已售罄','Sold out','Нет в наличии'],
        '请选择商品类型':['請選擇商品類型','Select an option','Выберите вариант'], '请检查填写内容':['請檢查填寫內容','Check the form fields','Проверьте поля формы'], '请填写查询密码（至少6个字符）':['請填寫查詢密碼（至少6個字元）','Enter a query password (at least 6 characters)','Введите пароль заказа (не менее 6 символов)'],
        '请选择有效数量':['請選擇有效數量','Enter a valid quantity','Введите допустимое количество'], '渠道价不叠加优惠券':['渠道價不疊加優惠券','Coupons do not apply to channel prices','Купоны не применяются к оптовой цене'],
        '商品':['商品','Product','Товар'], '商品规格':['商品規格','Option','Вариант'], '购买数量':['購買數量','Quantity','Количество'], '小计':['小計','Subtotal','Стоимость товаров'], '支付手续费':['付款手續費','Payment fee','Комиссия'], '实付金额':['實付金額','Total','Итого'], '支付方式':['付款方式','Payment method','Способ оплаты'], '联系方式':['聯絡方式','Contact','Контакт'],
        '零售价':['零售價','Retail price','Розничная цена'], '渠道价':['渠道價','Channel price','Оптовая цена'], '价格':['價格','Price','Цена'], '处理中…':['處理中…','Processing…','Обработка…'], '确认支付':['確認付款','Confirm payment','Подтвердить оплату'],
        '链接已复制':['連結已複製','Link copied','Ссылка скопирована'], '复制失败，请手动复制':['複製失敗，請手動複製','Copy failed. Please copy manually.','Не удалось скопировать. Скопируйте вручную.'], '暂时没有可选卡密':['暫時沒有可選卡密','No codes available for selection','Нет доступных кодов для выбора'], '选择卡密':['選擇卡密','Select a code','Выберите код'], '随机发货':['隨機出貨','Random delivery','Случайный код'], '价格已更新，请重新确认订单':['價格已更新，請重新確認訂單','Price changed. Confirm your order again.','Цена изменилась. Подтвердите заказ заново.'],
        '请填写订单号或联系方式':['請填寫訂單編號或聯絡方式','Enter an order number or contact','Введите номер заказа или контакт'], '正在查询…':['正在查詢…','Searching…','Поиск…'], '未找到相关订单':['未找到相關訂單','No orders found','Заказы не найдены'], '订单号':['訂單編號','Order number','Номер заказа'], '待付款':['待付款','Awaiting payment','Ожидает оплаты'], '已付款':['已付款','Paid','Оплачен'], '等待发货':['等待出貨','Awaiting delivery','Ожидает доставки'], '已发货':['已出貨','Delivered','Доставлен'], '下单时间':['下單時間','Created','Дата заказа'], '付款时间':['付款時間','Paid at','Дата оплаты'], '查询密码':['查詢密碼','Query password','Пароль заказа'], '查看交付内容':['查看交付內容','View delivery','Получить заказ'], '复制订单号':['複製訂單編號','Copy order number','Скопировать номер заказа'], '复制交付内容':['複製交付內容','Copy delivery','Скопировать данные'], '已复制':['已複製','Copied','Скопировано'], '订单尚未付款':['訂單尚未付款','This order is awaiting payment','Заказ ещё не оплачен'], '付款结果确认中，请勿重复付款':['付款結果確認中，請勿重複付款','Confirming payment. Do not pay again.','Проверяем оплату. Не оплачивайте повторно.'], '确认时间较长，请稍后刷新查询':['確認時間較長，請稍後重新查詢','Confirmation is taking longer. Check again shortly.','Подтверждение задерживается. Проверьте заказ позже.'], '上一页':['上一頁','Previous','Назад'], '下一页':['下一頁','Next','Далее'], '使用说明':['使用說明','Instructions','Инструкция'], '缺少支付地址，请查询订单或联系客服':['缺少付款地址，請查詢訂單或聯絡客服','Payment address is unavailable. Check the order or contact support.','Адрес оплаты недоступен. Проверьте заказ или обратитесь в поддержку.'], '支付地址无效，请联系客服':['付款地址無效，請聯絡客服','Invalid payment address. Contact support.','Недействительный адрес оплаты. Обратитесь в поддержку.'], '密码错误':['密碼錯誤','Incorrect password','Неверный пароль']
    };
    const locale = () => {
        const value = window.Storefront?.locale || document.documentElement.lang || 'zh-cn';
        return value === 'zh-TW' || value === 'zh-Hant' ? 'zh-tw' : (value === 'zh-CN' || value === 'zh-Hans' ? 'zh-cn' : (value === 'vi-VN' ? 'vi' : value));
    };
    const t = value => {
        const index = ['zh-tw','en','ru'].indexOf(locale());
        // Vietnamese fixed labels come from the bundled storefront dictionary.
        // Entity names and descriptions remain literal values returned by the server.
        const translated = index >= 0 ? dictionary[value]?.[index] : undefined;
        return translated != null ? translated : (window.Storefront?.t ? window.Storefront.t(value) : (typeof i18n === 'function' ? i18n(value) : value));
    };
    const append = (params, key, value) => {
        if (value !== null && typeof value === 'object') Object.entries(value).forEach(([name, child]) => append(params, `${key}[${name}]`, child));
        else params.append(key, value == null ? '' : String(value));
    };
    async function post(path, values, signal) {
        const body = new URLSearchParams(); Object.entries(values || {}).forEach(([key,value]) => append(body,key,value));
        const response = await fetch(path, {method:'POST', credentials:'same-origin', headers:{'X-Store-Locale':locale(),'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body,signal});
        if (!response.ok) throw new Error(t('请求失败，请稍后重试'));
        const data = await response.json();
        if (data.code !== 200) throw new Error(t(data.msg || '请求失败，请稍后重试'));
        return data.data;
    }
    const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const url = path => window.Storefront?.url ? window.Storefront.url(path) : (locale()==='zh-cn'?'':'/'+locale())+path;
    const money = value => { const n=Number(value); return Number.isFinite(n) ? n.toFixed(2) : '—'; };
    const currency = () => typeof format !== 'undefined' && format.currencySymbol ? format.currencySymbol() : '¥';
    async function copy(value) { try { await navigator.clipboard.writeText(String(value)); (typeof message !== 'undefined' && message.success ? message.success(t('已复制')) : void 0); } catch (_) { (typeof message !== 'undefined' && message.error ? message.error(t('复制失败，请手动复制')) : void 0); } }
    window.StoreCommerce = {t,post,escape,url,money,currency,copy,locale};
})();
