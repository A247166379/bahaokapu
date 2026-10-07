(function () {
    'use strict';
    const config = typeof getVar === 'function' ? getVar('DISPLAY_CURRENCY') || {} : {};
    const business = typeof getVar === 'function' ? getVar('CURRENCY') || {} : {};
    const locale = String(document.documentElement.lang || 'zh-cn').toLowerCase();
    const languages = {
        'zh-cn': {select:'显示币种', auto:'跟随语言', reference:'参考折算价', updated:'汇率更新', cached:'使用最近汇率', names:['人民币','美元','俄罗斯卢布','越南盾','港币','新台币']},
        'zh-tw': {select:'顯示幣種', auto:'跟隨語言', reference:'參考換算價', updated:'匯率更新', cached:'使用最近匯率', names:['人民幣','美元','俄羅斯盧布','越南盾','港幣','新臺幣']},
        en: {select:'Display currency', auto:'Follow language', reference:'Converted estimate', updated:'Rates updated', cached:'Using cached rates', names:['Chinese yuan','US dollar','Russian ruble','Vietnamese dong','Hong Kong dollar','Taiwan dollar']},
        ru: {select:'Валюта отображения', auto:'По языку', reference:'Ориентировочная цена', updated:'Курс обновлён', cached:'Сохранённый курс', names:['Китайский юань','Доллар США','Российский рубль','Вьетнамский донг','Гонконгский доллар','Тайваньский доллар']},
        vi: {select:'Tiền tệ hiển thị', auto:'Theo ngôn ngữ', reference:'Giá quy đổi tham khảo', updated:'Tỷ giá cập nhật', cached:'Dùng tỷ giá đã lưu', names:['Nhân dân tệ','Đô la Mỹ','Rúp Nga','Đồng Việt Nam','Đô la Hồng Kông','Đô la Đài Loan']}
    };
    const words = languages[locale] || languages.en;
    const codes = ['CNY','USD','RUB','VND','HKD','TWD'];
    const defaults = {'zh-cn':'CNY','zh-tw':'HKD',en:'USD',ru:'RUB',vi:'VND'};
    const expiresAt = (Number(config.last_update_unix) + 259200) * 1000;
    let active = config.enabled === true && config.base_code === 'CNY' && Number(config.last_update_unix) > 0 && Date.now() <= expiresAt;
    const decimals = code => code === 'VND' ? 0 : 2;
    const validRate = code => code === 'CNY' || (active && Number.isFinite(Number(config.rates?.[code])) && Number(config.rates[code]) > 0);
    const storageKey = 'store_display_currency';
    let saved = null;
    try { saved = localStorage.getItem(storageKey); } catch (_) {}
    let manual = active && codes.includes(saved) && validRate(saved);
    let selected = active && codes.includes(saved) && validRate(saved) ? saved : active && validRate(defaults[locale]) ? defaults[locale] : 'CNY';
    const number = (value,code) => {
        try { return new Intl.NumberFormat(locale,{minimumFractionDigits:decimals(code),maximumFractionDigits:decimals(code)}).format(value); }
        catch (_) { return value.toFixed(decimals(code)); }
    };
    const baseCode = String(business.code || 'CNY');
    const baseText = value => baseCode === 'CNY' ? 'CNY ¥' + number(value,'CNY') : baseCode + ' ' + Number(value).toFixed(Number(business.decimals) || 2);
    function amount(value) {
        if (value === null || value === '' || typeof value === 'boolean') return null;
        const n = Number(value);
        return Number.isFinite(n) && n >= 0 ? n : null;
    }
    function converted(value, code) {
        const n = amount(value);
        if (n === null || !codes.includes(code) || !validRate(code)) return null;
        const adjustment = Number(config.adjustments?.[code] || 0);
        if (!Number.isFinite(adjustment) || adjustment < -20 || adjustment > 50) return null;
        const result = n * (code === 'CNY' ? 1 : Number(config.rates[code])) * (code === 'CNY' ? 1 : 1 + adjustment / 100);
        return Number.isFinite(result) ? result : null;
    }
    function strings(value) {
        const n = amount(value);
        if (n === null) return {primary:'—',secondary:'',foreign:false};
        const foreign = active && selected !== 'CNY' && validRate(selected);
        const result = foreign ? converted(n,selected) : null;
        return result === null ? {primary:baseCode === 'CNY' ? '¥' + number(n,'CNY') : baseText(n),secondary:'',foreign:false}
            : {primary:'≈ ' + selected + ' ' + number(result,selected),secondary:baseText(n),foreign:true};
    }
    function render(node,value) {
        if (!node) return;
        const n = amount(value);
        if (n === null) { clear(node); node.textContent='—'; return; }
        node.dataset.displayBaseAmount = String(n);
        const text = strings(n);
        const main = document.createElement('span');
        main.className='store-display-primary'; main.textContent=text.primary;
        node.replaceChildren(main);
        if (text.secondary) {
            const secondary=document.createElement('span'); secondary.className='store-display-base'; secondary.textContent=text.secondary; node.append(secondary);
        }
        node.classList.add('store-display-price');
        node.classList.toggle('is-converted',text.foreign);
        node.title=text.foreign ? words.reference : baseText(n);
    }
    function clear(node) {
        if (!node) return;
        delete node.dataset.displayBaseAmount;
        node.classList.remove('store-display-price','is-converted');
        node.removeAttribute('title');
    }
    function describe(value) {
        const text = strings(value);
        return text.foreign ? text.primary + ' (' + text.secondary + ')' : text.primary;
    }
    function updatedText() {
        const time=Number(config.last_update_unix);
        if (!Number.isFinite(time) || time <= 0) return '';
        let date;
        try { date=new Intl.DateTimeFormat(locale,{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}).format(new Date(time*1000)); }
        catch (_) { date=new Date(time*1000).toISOString().slice(0,16).replace('T',' '); }
        return words.reference + ' · ' + (config.status === 'stale' ? words.cached : words.updated) + ' ' + date;
    }
    function refresh() {
        document.querySelectorAll('[data-display-base-amount]').forEach(node => render(node,node.dataset.displayBaseAmount));
        document.querySelectorAll('[data-display-currency-note]').forEach(node => {
            node.hidden=!active || selected === 'CNY';
            node.textContent=updatedText();
        });
        document.querySelectorAll('[data-display-currency-select]').forEach(node => {
            node.value=manual ? selected : 'AUTO';
            node.title=words.select + ': ' + selected + ' · ' + (manual ? words.names[codes.indexOf(selected)] : words.auto);
        });
        document.querySelectorAll('[data-display-currency-value]').forEach(node => { node.textContent=selected; });
    }
    function select(code) {
        if (!active) return false;
        if (code === 'AUTO') {
            manual=false; selected=validRate(defaults[locale]) ? defaults[locale] : 'CNY';
            try { localStorage.removeItem(storageKey); } catch (_) {}
        } else {
            if (!codes.includes(code) || !validRate(code)) return false;
            manual=true; selected=code;
            try { localStorage.setItem(storageKey,code); } catch (_) {}
        }
        refresh();
        document.dispatchEvent(new CustomEvent('store:currencychange',{detail:{code}}));
        return true;
    }
    function init() {
        document.querySelectorAll('[data-display-currency-control]').forEach(node => { node.hidden=!active; });
        document.querySelectorAll('[data-display-currency-select]').forEach(node => {
            node.setAttribute('aria-label',words.select); node.title=words.select;
            node.replaceChildren();
            const automatic=document.createElement('option'); automatic.value='AUTO'; automatic.textContent=(defaults[locale] || 'CNY') + ' · ' + words.auto; node.append(automatic);
            codes.filter(validRate).forEach((code) => {
                const option=document.createElement('option'); option.value=code; option.textContent=code + ' · ' + words.names[codes.indexOf(code)]; node.append(option);
            });
            node.value=manual ? selected : 'AUTO';
            if (!node.dataset.currencyBound) { node.dataset.currencyBound='1'; node.addEventListener('change',() => select(node.value)); }
        });
        document.querySelectorAll('[data-display-currency-credit]').forEach(node => { node.hidden=!active; });
        refresh();
    }
    window.StorePriceDisplay={render,clear,describe,converted,strings,select,refresh,init,get code(){return selected;}};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',init,{once:true}); else init();
    if (active) setTimeout(() => { active=false; selected='CNY'; init(); },Math.max(1,expiresAt-Date.now()+1));
})();
