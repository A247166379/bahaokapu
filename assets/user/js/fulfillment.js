/**
 * 付款成功后的统一交付引导。
 *
 * 卡密仍然只由后端在支付网关验签、金额核对通过后发放；本文件只负责把已经
 * 发放的卡密清楚地展示给买家，并引导买家前往固定兑换页完成充值。
 */
!function (window, document) {
    'use strict';

    const REDEEM_URL = 'https://appvip.lol/recharge';

    const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);

    const textWithBreaks = value => esc(value).replace(/\r?\n/g, '<br>');

    function installStyle() {
        if (document.getElementById('fulfillment-guide-style')) return;

        const style = document.createElement('style');
        style.id = 'fulfillment-guide-style';
        style.textContent = `
            .fg-wrap{padding:22px;box-sizing:border-box;color:#172033;font-family:inherit}
            .fg-success{display:flex;align-items:center;gap:12px;margin-bottom:18px;padding:13px 15px;border:1px solid #bce9ca;border-radius:14px;background:#f0fff5;color:#16733a}
            .fg-success__icon{display:grid;place-items:center;flex:0 0 30px;width:30px;height:30px;border-radius:50%;background:#20a65a;color:#fff;font-size:16px}
            .fg-success strong{display:block;font-size:16px;line-height:1.35}.fg-success span{display:block;margin-top:2px;font-size:12px;color:#4d7b5f}
            .fg-label{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 8px;font-size:13px;font-weight:700;color:#3b465a}
            .fg-label small{font-weight:400;color:#8b94a5}
            .fg-secret-code{display:block;max-height:190px;overflow:auto;padding:15px 16px;border:1px dashed #9cadd0;border-radius:12px;background:#f7f9ff;color:#121827;font:600 14px/1.75 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-all;user-select:text;-webkit-user-select:text}
            .fg-actions{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.25fr);gap:10px;margin-top:12px}
            .fg-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:44px;padding:10px 14px;border:1px solid #d8deea;border-radius:11px;background:#fff;color:#344054;font-weight:700;text-decoration:none!important;cursor:pointer;transition:.15s ease}
            .fg-btn svg,.fg-warning svg{width:16px;height:16px;flex:0 0 16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
            .fg-btn:hover{border-color:#a7b6d3;background:#f7f9fc;color:#202b3d}.fg-btn--primary{border-color:#4f6ef7;background:#4f6ef7;color:#fff!important;box-shadow:0 7px 18px rgba(79,110,247,.22)}
            .fg-btn--primary:hover{border-color:#3e5de7;background:#3e5de7;color:#fff}.fg-btn--text{grid-column:1/-1;min-height:34px;padding:5px;border:0;background:transparent;font-size:12px;color:#7a8496;box-shadow:none}
            .fg-steps{margin-top:18px;padding:15px;border-radius:14px;background:#f7f8fb;border:1px solid #e8ebf1}.fg-steps__title{margin-bottom:11px;font-size:13px;font-weight:800;color:#344054}
            .fg-step-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.fg-step{display:flex;gap:8px;align-items:flex-start;padding:9px;border-radius:10px;background:#fff;color:#596376;font-size:12px;line-height:1.45}
            .fg-step b{display:grid;place-items:center;flex:0 0 22px;width:22px;height:22px;border-radius:50%;background:#e7ecff;color:#4663e8;font-size:12px}.fg-step strong{display:block;color:#273248;font-size:12px}
            .fg-warning{display:flex;gap:8px;margin-top:13px;color:#9a5b17;font-size:12px;line-height:1.55}.fg-warning i{margin-top:2px}
            .fg-note{margin-top:14px;padding:12px 14px;border-left:3px solid #8296e8;border-radius:8px;background:#f5f7ff;color:#4d5870;font-size:13px;line-height:1.7;word-break:break-word}
            .fg-note__title{margin-bottom:4px;font-weight:800;color:#344054}
            .fg-inline{margin-top:4px;padding:18px;border:1px solid #e4e8f0;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(26,40,72,.06)}
            .fg-inline .fg-wrap{padding:0}.fg-inline .fg-success{margin-bottom:14px}
            .fg-waiting{display:flex;gap:12px;align-items:flex-start;padding:15px;border:1px solid #f4d9a4;border-radius:12px;background:#fff9ed;color:#7b5118}
            .fg-waiting i{margin-top:3px}.fg-waiting strong{display:block;margin-bottom:3px}.fg-waiting span{display:block;font-size:13px;line-height:1.55;color:#8c672e}
            .payment-sync-notice{display:flex;align-items:flex-start;gap:12px;margin:0 0 16px;padding:14px 16px;border:1px solid #cbd7ff;border-radius:13px;background:#f4f7ff;color:#354f9b}
            .payment-sync-notice i{margin-top:3px}.payment-sync-notice strong{display:block;margin-bottom:2px}.payment-sync-notice span{font-size:13px;color:#6575a3}.payment-sync-notice.is-paid{border-color:#bce9ca;background:#f0fff5;color:#16733a}.payment-sync-notice.is-waiting{border-color:#f4d9a4;background:#fff9ed;color:#7b5118}
            @media(max-width:640px){.fg-wrap{padding:17px}.fg-actions{grid-template-columns:1fr}.fg-btn--text{grid-column:auto}.fg-step-list{grid-template-columns:1fr}.fg-step{align-items:center}.fg-inline{padding:14px}}
        `;
        document.head.appendChild(style);
    }

    function bodyHtml(tradeNo, secret, leaveMessage) {
        const copyIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M5 15V5a2 2 0 0 1 2-2h10"></path></svg>';
        const openIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"></path></svg>';
        const downloadIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"></path><path d="m8 11 4 4 4-4"></path><path d="M4 19h16"></path></svg>';
        const shieldIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4.5 6v5.5c0 4.5 3.1 7.8 7.5 9.5 4.4-1.7 7.5-5 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>';
        const note = leaveMessage
            ? `<div class="fg-note"><div class="fg-note__title">商家说明</div>${textWithBreaks(leaveMessage)}</div>`
            : '';

        return `<div class="fg-wrap" data-trade-no="${esc(tradeNo || '')}">
            <div class="fg-success">
                <div class="fg-success__icon" aria-hidden="true">✓</div>
                <div><strong>付款成功，卡密已自动发放</strong><span>请先复制并妥善保存，再前往兑换页面充值</span></div>
            </div>
            <div class="fg-label"><span>您的充值卡密</span><small>点击卡密也可以复制</small></div>
            <div class="fg-secret-code" role="button" tabindex="0" data-fg-action="copy" title="点击复制卡密">${esc(secret)}</div>
            <div class="fg-actions">
                <button type="button" class="fg-btn" data-fg-action="copy">${copyIcon}<span>复制卡密</span></button>
                <a class="fg-btn fg-btn--primary" href="${REDEEM_URL}" target="_blank" rel="noopener noreferrer">${openIcon}<span>前往兑换充值</span></a>
                <button type="button" class="fg-btn fg-btn--text" data-fg-action="download">${downloadIcon}<span>下载卡密备用</span></button>
            </div>
            <div class="fg-steps">
                <div class="fg-steps__title">兑换步骤</div>
                <div class="fg-step-list">
                    <div class="fg-step"><b>1</b><div><strong>复制卡密</strong>复制上方完整内容</div></div>
                    <div class="fg-step"><b>2</b><div><strong>打开兑换页</strong>点击“前往兑换充值”</div></div>
                    <div class="fg-step"><b>3</b><div><strong>提交充值</strong>粘贴卡密并按页面提示完成</div></div>
                </div>
            </div>
            <div class="fg-warning">${shieldIcon}<span>卡密等同于商品凭证，请勿发送给陌生人；兑换完成前请保留订单号。</span></div>
            ${note}
        </div>`;
    }

    function getCard(target) {
        return target.closest('.fg-wrap');
    }

    function getSecret(target) {
        const card = getCard(target);
        const code = card && card.querySelector('.fg-secret-code');
        return code ? code.textContent : '';
    }

    function copySecret(target) {
        const secret = getSecret(target);
        if (!secret) {
            if (window.message) message.error('没有可复制的卡密');
            return;
        }

        const success = () => window.message && message.success('卡密已复制，请前往兑换页面使用');
        const failure = () => window.message && message.error('复制失败，请手动选中卡密复制');
        if (window.util && typeof util.copyTextToClipboard === 'function') {
            util.copyTextToClipboard(secret, success, failure);
            return;
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(secret).then(success).catch(failure);
            return;
        }
        failure();
    }

    function downloadSecret(target) {
        const card = getCard(target);
        const secret = getSecret(target);
        if (!card || !secret) return;

        const blob = new Blob([secret], {type: 'text/plain;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${card.getAttribute('data-trade-no') || 'card'}.txt`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    if (!window.__fulfillmentGuideBound) {
        window.__fulfillmentGuideBound = true;
        document.addEventListener('click', event => {
            const target = event.target.closest && event.target.closest('[data-fg-action]');
            if (!target) return;
            const action = target.getAttribute('data-fg-action');
            if (action === 'copy') {
                event.preventDefault();
                copySecret(target);
            } else if (action === 'download') {
                event.preventDefault();
                downloadSecret(target);
            }
        });
        document.addEventListener('keydown', event => {
            if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.fg-secret-code[data-fg-action="copy"]')) {
                event.preventDefault();
                copySecret(event.target);
            }
        });
    }

    installStyle();

    const guide = {
        redeemUrl: REDEEM_URL,

        inlineHtml(tradeNo, secret, leaveMessage) {
            installStyle();
            return `<div class="fg-inline">${bodyHtml(tradeNo, secret, leaveMessage)}</div>`;
        },

        waitingHtml() {
            installStyle();
            return `<div class="fg-waiting"><span aria-hidden="true">⏳</span><div><strong>付款已确认，商品正在处理</strong><span>当前商品需要人工发货。发货完成后，可在本页面或“我的订单”中查看。</span></div></div>`;
        },

        show(tradeNo, secret, leaveMessage) {
            installStyle();
            if (secret == null || String(secret).trim() === '') {
                if (window.message) message.error('卡密暂未生成，请稍后在订单查询中查看');
                return;
            }
            if (!window.layer || typeof layer.open !== 'function') return;

            layer.open({
                type: 1,
                title: '<span style="color:#20a65a;font-weight:900" aria-hidden="true">✓</span> 订单交付成功',
                area: [Math.min((window.innerWidth || 560) - 24, 560) + 'px', 'auto'],
                maxHeight: Math.max((window.innerHeight || 720) - 40, 360),
                shadeClose: false,
                content: bodyHtml(tradeNo, secret, leaveMessage)
            });
        }
    };

    window.FulfillmentGuide = guide;

    // 商品页余额支付/零元订单原本调用 treasure.show；覆盖展示层即可复用同一交付流程。
    try {
        if (typeof treasure !== 'undefined' && treasure && typeof treasure.show === 'function') {
            treasure.show = (tradeNo, secret, leaveMessage) => guide.show(tradeNo, secret, leaveMessage);
        }
    } catch (error) {
        // 某些会员中心页面没有 treasure，对交付引导本身没有影响。
    }
}(window, document);
