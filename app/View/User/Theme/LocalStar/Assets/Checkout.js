(function () {
    'use strict';
    const root = document.querySelector('[data-store-checkout]');
    if (!root || root.dataset.initialized) return;
    root.dataset.initialized = '1';
    window.StoreCheckoutCleanup?.();
    const c = window.StoreCommerce;
    if (!c) return;
    const {t,post,escape,url,money,currency} = c;
    const item = getVar('_var_item');
    const form = root.querySelector('.store-purchase-form');
    const price = root.querySelector('.price');
    const buy = root.querySelector('.store-buy-button');
    const error = root.querySelector('.store-form-error');
    let alive = true, sequence = 0, quote = null, stock = null, payments = [], busy = false, recoveringPayments = false;
    const nonce = crypto.getRandomValues(new Uint8Array(14));
    const requestNo = btoa(String.fromCharCode(...nonce)).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,''); // 112 random bits, 19 chars: legacy CHAR(19).
    const abort = new AbortController();
    window.StoreCheckoutCleanup = () => { alive=false; abort.abort(); root.querySelector('.store-draft-dialog')?.close(); };
    window.addEventListener('pagehide', window.StoreCheckoutCleanup, {once:true});
    const showError = text => { error.textContent=text; error.hidden=!text; };
    const min = Math.max(1, Number(item.minimum)||1), max = Number(item.maximum)||2147483647;
    function data() {
        const values = {}; const fd = new FormData(form);
        for (const [name,value] of fd) {
            if(name.endsWith('[]')) { const key=name.slice(0,-2); (values[key]||(values[key]=[])).push(value); }
            else values[name]=value;
        }
        values.item_id=item.id;
        values.race=root.querySelector('.switch-race.is-primary')?.dataset.sku||'';
        values.sku={}; root.querySelectorAll('.switch-sku.is-primary').forEach(el=>{values.sku[el.dataset.sku]=el.dataset.value;});
        // The buyer chooses a method; the API supplies the current eligible route.
        const selected=form.querySelector('input[name="pay_id"]:checked');
        const payment=payments.find(pay=>pay.method===selected?.dataset.paymentMethod&&String(pay.id)===String(values.pay_id));
        values.payment_method=paymentAvailable(payment)?payment.method:'';
        if(!values.payment_method)values.pay_id=0;
        return values;
    }
    function resetDraft() { const input=form.querySelector('[name=card_id]');if(input)input.value='';const button=root.querySelector('.optional-card');if(button)button.textContent=t('选择卡密'); }
    function paymentAvailable(pay) { return pay?.available===true&&['alipay','wxpay','usdt'].includes(pay.method)&&Number.isInteger(Number(pay.id))&&Number(pay.id)>0; }
    function selectedPayment(values) { return payments.find(pay=>pay.method===values.payment_method&&String(pay.id)===String(values.pay_id)); }
    function paymentStateError(error) { return ['支付路由已变化，请刷新页面重新选择','该支付方式暂不可用，请重新选择','该支付方式暂不可用，请选择其他支付方式','支付方式已更新，请重新选择'].some(text=>error?.message===text||error?.message===t(text)); }
    function paymentErrorText(error) { return paymentStateError(error)?t('选择支付方式'):error.message; }
    function updateBuy() { buy.disabled=busy||recoveringPayments||!quote||!(stock>0)||!paymentAvailable(selectedPayment(data())); }
    function displayQuote(value) {
        root.querySelector('[data-display-fee]')?.remove();
        if(window.StorePriceDisplay)window.StorePriceDisplay.render(price,value.total);else price.textContent=currency()+money(value.total);
        if(Number(value.fee)>0){const small=document.createElement('small');small.dataset.displayFee='1';small.className='store-display-fee';small.append(document.createTextNode(t('支付手续费')+' '));const fee=document.createElement('span');if(window.StorePriceDisplay)window.StorePriceDisplay.render(fee,value.fee);else fee.textContent=currency()+money(value.fee);small.append(fee);price.after(small);}
    }
    function sameAmounts(left,right) { return ['price','fee','total'].every(key=>money(left?.[key])===money(right?.[key])); }
    function channelOptions() {
        const selected = data().price_type==='channel';
        root.querySelectorAll('.switch-race').forEach(el=>{el.disabled=selected&&!Object.prototype.hasOwnProperty.call(item.channel_prices||{},el.dataset.sku);});
        const chosen = root.querySelector('.switch-race.is-primary');
        if(chosen?.disabled){chosen.classList.remove('is-primary');root.querySelector('.switch-race:not(:disabled)')?.classList.add('is-primary');}
        const coupon=root.querySelector('.store-coupon'); if(coupon){coupon.hidden=selected; if(selected)coupon.querySelector('input').value='';}
    }
    async function refresh({allowPaymentRecovery=true}={}) {
        channelOptions(); const rev=++sequence; quote=null; stock=null; updateBuy(); showError('');window.StorePriceDisplay?.clear(price);price.textContent=t('加载中…');
        root.querySelector('[data-display-fee]')?.remove();
        const values=data();
        // Display the item price independently when no available method is selected.
        const payment=payments.find(pay=>pay.method===values.payment_method&&String(pay.id)===String(values.pay_id));
        if(!paymentAvailable(payment)){values.pay_id=0;values.payment_method='';}
        try {
            const results = await Promise.all([post('/user/api/index/valuation',values,abort.signal),post('/user/api/index/stock',values,abort.signal)]);
            if(!alive||!root.isConnected||rev!==sequence)return;
            quote=results[0]; stock=Number(results[1].stock_state);
            displayQuote(quote);
            root.querySelector('.item-stock').textContent=stock>0?t('库存')+' '+t(String(results[1].stock)):t('已售罄');
            updateBuy();
        } catch(e) { if(e.name==='AbortError'||rev!==sequence||!alive)return;quote=null;window.StorePriceDisplay?.clear(price);price.textContent=t('价格暂不可用');showError(paymentErrorText(e));updateBuy();if(allowPaymentRecovery&&paymentStateError(e))await recoverPaymentSelection(); }
    }
    function validate(values) {
        if(!Number.isInteger(Number(values.num))||Number(values.num)<min||Number(values.num)>max)throw new Error(t('请选择有效数量'));
        if(!form.checkValidity()){form.reportValidity();throw new Error(t('请检查填写内容'));}
        if(item.password_status===1&&!item.login&&String(values.password||'').length<6)throw new Error(t('请填写查询密码（至少6个字符）'));
        for(const widget of item.widget||[]) {
            if(widget.type==='custom')continue;
            const value=Array.isArray(values[widget.name])?values[widget.name].join(','):String(values[widget.name]||'');
            if(widget.required && !value)throw new Error(widget.error||t('请检查填写内容'));
            if(widget.regex) { let pattern;try{pattern=new RegExp(widget.regex);}catch(_){throw new Error(t('请检查填写内容'));}if(!pattern.test(value))throw new Error(widget.error||t('请检查填写内容')); }
        }
        if(!paymentAvailable(selectedPayment(values)))throw new Error(t('选择支付方式'));
    }
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;const values=data();
        try { validate(values); } catch(e) { showError(e.message);return; }
        if(!quote||!(stock>0)){showError(t('价格暂不可用'));return;}
        // One explicit click owns the fresh quote and the single submission below.
        const rev=sequence, displayedQuote=quote, snapshot=JSON.stringify(values);
        busy=true;buy.textContent=t('处理中…');showError('');updateBuy();
        let tradeStarted=false;
        try {
            const fresh=await post('/user/api/index/valuation',values,abort.signal);
            if(!alive||!root.isConnected||rev!==sequence||snapshot!==JSON.stringify(data()))return;
            validate(values);if(!paymentAvailable(selectedPayment(values)))throw new Error(t('选择支付方式'));
            quote=fresh;displayQuote(fresh);
            if(!sameAmounts(displayedQuote,fresh)){showError(t('价格已更新，请重新确认订单'));return;}
            tradeStarted=true;
            const result=await post('/user/api/order/trade',{...values,quoted_amount:fresh.total,request_no:requestNo},abort.signal);if(!alive)return;
            if(result.secret!=null||!result.url){location.assign(url('/order/'+result.tradeNo));return;}
            let target;
            try { target=new URL(result.url,location.origin); } catch (_) { location.assign(url('/order/'+result.tradeNo));return; }
            if(!['http:','https:'].includes(target.protocol)||target.username||target.password){location.assign(url('/order/'+result.tradeNo));return;}
            // Internal cashier keeps its native endpoint; gateway callback/signature are unchanged.
            if(target.origin===location.origin&&/^\/user\/pay\/order\.\d{18}\.[12]$/.test(target.pathname))target.searchParams.set('lang',c.locale());
            location.assign(target.href);
        }catch(e){if(e.name!=='AbortError'&&alive&&(tradeStarted||rev===sequence)){showError(paymentErrorText(e));if(tradeStarted)refreshCaptcha();if(paymentStateError(e))await recoverPaymentSelection();}}
        finally {busy=false;if(alive){buy.textContent=t('立即购买');updateBuy();}}
    });
    function refreshCaptcha(){const img=root.querySelector('.captcha-img');if(img)img.src='/user/captcha/image?action=trade&lang='+encodeURIComponent(c.locale())+'&_t='+Date.now();}
    root.querySelector('.store-captcha-refresh')?.addEventListener('click',refreshCaptcha);
    form.addEventListener('change',event=>{if(event.target.matches('[name=num]')){event.target.value=Math.min(max,Math.max(min,Math.trunc(Number(event.target.value)||min)));resetDraft();}if(!event.target.closest('.store-widgets')&&!event.target.matches('[name=contact],[name=password],[name=captcha]'))refresh();});
    root.querySelectorAll('.switch-race,.switch-sku').forEach(el=>el.addEventListener('click',()=>{const group=el.parentElement;group.querySelectorAll('.is-primary').forEach(n=>n.classList.remove('is-primary'));el.classList.add('is-primary');resetDraft();refresh();}));
    ['sub','add'].forEach((action,index)=>root.querySelector('.change-num-'+action).addEventListener('click',()=>{const input=form.querySelector('[name=num]');input.value=Math.min(max,Math.max(min,(Number(input.value)||min)+(index?1:-1)));resetDraft();refresh();}));
    root.querySelector('.shared-button').addEventListener('click',()=>c.copy(location.href));
    const initial = new URL(location.href).searchParams.get('price_type');if(initial==='channel'&&item.can_channel){form.querySelector('[name=price_type][value=channel]').checked=true;}
    // Refresh the bundled Alipay icon without changing administrator-uploaded icons.
    function paymentIcon(icon) {
        return icon === '/assets/user/images/cash/alipay.png' ? icon + '?v=alipay-blue-20261003-r1' : icon;
    }
    function renderPayments(autoSelect=true){
            const first=autoSelect?payments.find(paymentAvailable):null;
            const list=root.querySelector('.pay-list');
            list.innerHTML=payments.map(pay=>{
                const available=paymentAvailable(pay);
                return `<label class="store-pay-option${available?'':' is-unavailable'}"${available?'':' aria-disabled="true"'}><input type="radio" name="pay_id" value="${escape(pay.id)}" data-payment-method="${escape(pay.method)}" ${pay===first?'checked':''}${available?'':' disabled'}>${pay.icon?`<img src="${escape(paymentIcon(pay.icon))}" alt="">`:''}<span>${escape(pay.name)}</span></label>`;
            }).join('');
            const fieldset=list.closest('fieldset');
            if(fieldset)fieldset.hidden=false;
    }
    async function loadPayments(autoSelect=true){
            const result=await post('/user/api/index/pay?itemId='+item.id,{},abort.signal);if(!alive)return;
            const methods=new Set();
            payments=(Array.isArray(result)?result:[]).filter(pay=>{
                if(!['alipay','wxpay','usdt'].includes(pay?.method)||methods.has(pay.method))return false;
                methods.add(pay.method);return true;
            });
            renderPayments(autoSelect);
    }
    async function recoverPaymentSelection(){
        if(recoveringPayments||!alive)return;
        recoveringPayments=true;quote=null;updateBuy();
        try{
            await loadPayments(false);if(!alive)return;
            // One bounded reload; the neutral price refresh cannot reload payments again.
            await refresh({allowPaymentRecovery:false});if(alive)showError(t('选择支付方式'));
        }catch(e){if(alive&&e.name!=='AbortError')showError(paymentErrorText(e));}
        finally{recoveringPayments=false;if(alive)updateBuy();}
    }
    const draftDialog=root.querySelector('.store-draft-dialog');let draftPage=1;
    async function drafts(){const list=draftDialog.querySelector('.store-draft-list');list.textContent=t('加载中…');try{const result=await post('/user/api/index/card',{...data(),page:draftPage,limit:10,'search-draft':draftDialog.querySelector('.store-draft-search').value},abort.signal);if(!alive)return;list.innerHTML='';for(const card of result.list||[]){const button=document.createElement('button');button.type='button';button.textContent=card.draft+(Number(card.draft_premium)>0?' +'+currency()+money(card.draft_premium):'');button.addEventListener('click',()=>{form.querySelector('[name=card_id]').value=card.id;form.querySelector('[name=num]').value=1;root.querySelector('.optional-card').textContent=card.draft;draftDialog.close();refresh();});list.append(button);}if(!list.children.length)list.textContent=t('暂时没有可选卡密');draftDialog.querySelector('.store-draft-prev').disabled=draftPage<=1;draftDialog.querySelector('.store-draft-next').disabled=draftPage*10>=Number(result.total);}catch(e){if(e.name!=='AbortError')list.textContent=e.message;}}
    root.querySelector('.optional-card')?.addEventListener('click',()=>{draftPage=1;draftDialog.showModal();drafts();});draftDialog.querySelector('.store-draft-close').addEventListener('click',()=>draftDialog.close());draftDialog.querySelector('.store-draft-search').addEventListener('change',()=>{draftPage=1;drafts();});draftDialog.querySelector('.store-draft-prev').addEventListener('click',()=>{draftPage--;drafts();});draftDialog.querySelector('.store-draft-next').addEventListener('click',()=>{draftPage++;drafts();});draftDialog.querySelector('.store-draft-random').addEventListener('click',()=>{form.querySelector('[name=card_id]').value='';root.querySelector('.optional-card').textContent=t('选择卡密');draftDialog.close();refresh();});
    loadPayments().then(()=>alive&&refresh()).catch(e=>{if(alive&&e.name!=='AbortError')showError(paymentErrorText(e));});
})();
