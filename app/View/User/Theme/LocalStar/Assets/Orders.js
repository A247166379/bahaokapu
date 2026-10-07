(function () {
    'use strict';
    const root=document.querySelector('[data-store-orders]');if(!root||root.dataset.initialized)return;root.dataset.initialized='1';
    window.StoreOrdersCleanup?.();const c=window.StoreCommerce;if(!c)return;const {t,post,escape,money,currency}=c;
    const form=root.querySelector('.store-query-form'),input=form.querySelector('input'),list=root.querySelector('.store-order-list'),state=root.querySelector('.store-orders-state'),pagination=root.querySelector('.store-orders-pagination');
    let alive=true,suspended=false,revision=0,page=1,keywords='',poll=null,polls=0;
    const controller=new AbortController();
    const current=rev=>alive&&!suspended&&root.isConnected&&rev===revision;
    function stopPoll(){clearTimeout(poll);poll=null;}
    function cleanup(){
        if(!alive)return;
        alive=false;revision++;controller.abort();stopPoll();
        window.removeEventListener('pagehide',pageHide);window.removeEventListener('pageshow',pageShow);
        if(window.StoreOrdersCleanup===cleanup)delete window.StoreOrdersCleanup;
    }
    function pageHide(event){
        if(!event.persisted){cleanup();return;}
        suspended=true;revision++;stopPoll();form.querySelector('button').disabled=false;
    }
    function pageShow(event){
        if(!event.persisted||!alive||!suspended)return;
        suspended=false;if(keywords)query();
    }
    window.StoreOrdersCleanup=cleanup;
    window.addEventListener('pagehide',pageHide);window.addEventListener('pageshow',pageShow);
    function instructions(target,raw){if(!raw)return;const block=document.createElement('div');block.className='store-order-message';const title=document.createElement('strong');title.textContent=t('使用说明');block.append(title);const content=document.createElement('div');content.innerHTML=String(raw);block.append(content);target.append(block);}
    function delivery(target,order,secret,widgets,message){
        target.innerHTML='';
        const textarea=document.createElement('textarea');textarea.className='store-secret-text';textarea.readOnly=true;textarea.rows=5;textarea.value=String(secret||'');textarea.setAttribute('aria-label',t('查看交付内容'));target.append(textarea);
        const copy=document.createElement('button');copy.type='button';copy.className='store-secondary store-secret-copy';copy.textContent=t('复制交付内容');copy.addEventListener('click',()=>c.copy(textarea.value));target.append(copy);
        if(widgets){for(const field of Object.values(widgets)){const block=document.createElement('p');block.textContent=(field.cn||'')+': '+String(field.value||'');target.append(block);}}
        instructions(target,message);
    }
    function card(order){
        const card=document.createElement('section');card.className='store-order-card';
        const fields=[[t('订单号'),order.trade_no],[t('购买数量'),order.card_num],[t('实付金额'),currency()+money(order.amount)],[t('支付方式'),order.payment_method_name||order.pay?.method_name||order.pay?.name||'—'],[t('联系方式'),order.contact||'—'],[t('下单时间'),order.create_time],[t('付款时间'),order.pay_time||'—']];
        if(order.race)fields.splice(1,0,[t('商品规格'),order.race]);for(const [key,value]of Object.entries(order.sku||{}))fields.splice(1,0,[key,value]);
        card.innerHTML=`<h2>${escape(order.commodity?.name||'')}</h2><div class="store-order-status"><span>${escape(t(Number(order.status)===1?'已付款':'待付款'))}</span>${Number(order.status)===1?`<span>${escape(t(Number(order.delivery_status)===1?'已发货':'等待发货'))}</span>`:''}</div><dl class="store-order-fields">${fields.map(([label,value])=>`<dt>${escape(label)}</dt><dd>${escape(value)}</dd>`).join('')}</dl>`;
        const copy=document.createElement('button');copy.type='button';copy.className='store-secondary store-order-copy';copy.textContent=t('复制订单号');copy.addEventListener('click',()=>c.copy(order.trade_no));card.append(copy);
        const target=document.createElement('div');card.append(target);
        if(Number(order.status)===1){
            if(Number(order.delivery_status)!==1){target.textContent=t('等待发货');}
            else if(order.password===true){
                const unlock=document.createElement('form');unlock.className='store-unlock';unlock.innerHTML=`<input type="password" required autocomplete="off" placeholder="${escape(t('查询密码'))}" aria-label="${escape(t('查询密码'))}"><button type="submit" class="store-buy-button">${escape(t('查看交付内容'))}</button>`;target.append(unlock);
                const error=document.createElement('p');error.className='store-form-error';error.setAttribute('role','alert');target.append(error);let loading=false;
                unlock.addEventListener('submit',async event=>{event.preventDefault();if(loading||!unlock.reportValidity())return;loading=true;const rev=revision,button=unlock.querySelector('button');button.disabled=true;button.textContent=t('加载中…');error.textContent='';try{const result=await post('/user/api/index/secret',{tradeNo:order.trade_no,password:unlock.querySelector('input').value},controller.signal);if(!current(rev)||!target.isConnected)return;delivery(target,order,result.secret,result.widget,result.leave_message);}catch(e){if(e.name!=='AbortError'&&current(rev)&&target.isConnected)error.textContent=e.message;}finally{loading=false;if(current(rev)&&target.isConnected){button.disabled=false;button.textContent=t('查看交付内容');}}});
            }else if(order.secret!=null){delivery(target,order,order.secret,null,order.leave_message);}
            else target.textContent=t('等待发货');
        }
        return card;
    }
    async function query(){
        if(!alive||suspended||!root.isConnected)return;
        stopPoll();const rev=++revision,search=keywords;state.textContent=t('正在查询…');form.querySelector('button').disabled=true;
        try{
            const result=await post('/user/api/index/query',{keywords:search,page,limit:10},controller.signal);if(!current(rev))return;
            list.innerHTML='';const orders=result.list||[];orders.forEach(order=>list.append(card(order)));
            state.textContent=orders.length?'':t('未找到相关订单');pagination.hidden=Number(result.total)<=10;
            root.querySelector('.store-orders-prev').disabled=page<=1;root.querySelector('.store-orders-next').disabled=page*10>=Number(result.total);root.querySelector('.store-orders-page').textContent=String(page);
            // Only a precise order route starts status polling. Contact searches do not poll or reveal private data.
            if(/^\d{18}$/.test(root.dataset.order)&&search===root.dataset.order){const exact=orders.find(order=>String(order.trade_no)===search);if(exact&&Number(exact.status)!==1){state.textContent=t('付款结果确认中，请勿重复付款');schedulePoll(rev,search);}}
        }catch(e){if(e.name!=='AbortError'&&current(rev)){state.textContent=e.message;list.innerHTML='';pagination.hidden=true;}}
        finally{if(current(rev))form.querySelector('button').disabled=false;}
    }
    function schedulePoll(rev,tradeNo){
        if(!current(rev)||keywords!==tradeNo)return;
        if(polls>=30){state.textContent=t('确认时间较长，请稍后刷新查询');return;}
        poll=setTimeout(async()=>{
            poll=null;if(!current(rev)||keywords!==tradeNo)return;
            polls++;
            try{
                const result=await post('/user/api/order/state',{tradeNo},controller.signal);
                if(!current(rev)||keywords!==tradeNo)return;
                if(Number(result.status)===1){query();return;}
                schedulePoll(rev,tradeNo);
            }catch(e){if(e.name!=='AbortError'&&current(rev))schedulePoll(rev,tradeNo);}
        },5000);
    }
    form.addEventListener('submit',event=>{event.preventDefault();keywords=input.value.trim();if(!keywords){revision++;stopPoll();form.querySelector('button').disabled=false;state.textContent=t('请填写订单号或联系方式');list.innerHTML='';pagination.hidden=true;return;}page=1;polls=0;query();});
    root.querySelector('.store-orders-prev').addEventListener('click',()=>{page=Math.max(1,page-1);query();});root.querySelector('.store-orders-next').addEventListener('click',()=>{page++;query();});
    if(input.value.trim()){keywords=input.value.trim();query();}
})();
