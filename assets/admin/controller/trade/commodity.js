!function () {
    let table;
    const namespace = '.mdTradeCommodityController';
    let controllerActive = true;
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => $('<div>').text(String(value ?? '')).html();
    //属性上下文转义（escapeHtml 走 text()->html() 不编码引号，拼进 src="…" 会被属性突破）
    const escapeAttr = value => String(value ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const commodityDeleteNames = (values, fallback) => Array.isArray(values) && values.length
        ? values.map(escapeHtml).join(i18n('、'))
        : escapeHtml(fallback || i18n('所选商品'));
    const commodityDeleteDetail = impact => {
        const groupNames = Array.isArray(impact.commodity_group_names) && impact.commodity_group_names.length
            ? `（${impact.commodity_group_names.map(escapeHtml).join('、')}）`
            : '';
        return `${i18n('卡密')} ${Number(impact.card_count || 0)} ${i18n('张、订单')} ${Number(impact.order_count || 0)} ${i18n('笔、优惠券')} ${Number(impact.coupon_count || 0)} ${i18n('张、商户映射')} ${Number(impact.merchant_mapping_count || 0)} ${i18n('条、工单')} ${Number(impact.ticket_count || 0)} ${i18n('条、商品分组')} ${Number(impact.commodity_group_count || 0)} ${i18n('个')}${groupNames}`;
    };
    const commodityDeleteSkipReason = impact => {
        const reasons = [];
        const blockedCount = Number(impact.blocked_count || 0);
        const missingCount = Number(impact.missing_count || 0);
        if (blockedCount > 0) {
            const names = commodityDeleteNames(impact.blocked_names, i18n('有关联业务的商品'));
            reasons.push(`${i18n('有关联业务')} ${blockedCount} ${i18n('个（')}${names}；${commodityDeleteDetail(impact)}）`);
        }
        if (missingCount > 0) {
            reasons.push(`${i18n('已不存在')} ${missingCount} ${i18n('个')}`);
        }
        return reasons.join('；') || i18n('没有可删除的商品');
    };
    const showCommodityBatchDeleteResult = response => {
        const result = response?.data || {};
        const deletedCount = Number(result.deleted_count ?? result.count ?? 0);
        const skippedCount = Number(result.skipped_count || 0);
        if (skippedCount > 0) {
            message.alert(
                `${i18n('批量删除完成：成功删除')} <b>${deletedCount} ${i18n('个')}</b>，${i18n('自动跳过')} <b>${skippedCount} ${i18n('个')}</b>。<br><br>${i18n('跳过原因：')}${commodityDeleteSkipReason(result)}`,
                'warning'
            );
            return;
        }
        message.success(`${i18n('已删除')} ${deletedCount} ${i18n('个商品')}`);
    };
    const batchSettingDefinitions = [
        {name: 'password_status', title: '下单密码'},
        {name: 'inventory_hidden', title: '隐藏库存'},
        {name: 'recommend', title: '推荐商品'},
    ];
    const batchSettingForm = definition => mobileAdminEnabled() ? {
        title: definition.title,
        name: definition.name,
        type: 'radio',
        default: 'keep',
        dict: [
            {id: 'keep', name: '保持原设置'},
            {id: 1, name: '开启'},
            {id: 0, name: '关闭'},
        ],
        tips: definition.tips
    } : {
        title: definition.title,
        name: definition.name,
        type: 'switch',
        text: '启用',
        tips: definition.tips
    };
    if (typeof window.__mdTradeCommodityDestroy === 'function') window.__mdTradeCommodityDestroy();
    const confirmCommodityDelete = (list, fallbackName, done) => {
        if (!controllerActive) return;
        util.post({
            url: '/admin/api/commodity/deleteImpact',
            data: {list: list},
            done: res => {
                if (!controllerActive) return;
                const impact = res.data || {};
                const names = commodityDeleteNames(impact.names, fallbackName || i18n('所选商品'));
                const detail = commodityDeleteDetail(impact);
                const requestedCount = Number(impact.requested_count || list.length);
                const deletableCount = Number(impact.deletable_count || 0);
                const missingCount = Number(impact.missing_count || 0);
                const skippedCount = Number(impact.skipped_count || 0);
                if (list.length === 1 && missingCount > 0) {
                    message.alert('该商品已不存在，请刷新列表后重试。', 'warning');
                    return;
                }
                if (deletableCount < 1) {
                    message.alert(
                        `${i18n('所选')} ${requestedCount} ${i18n('个商品均无法物理删除，已全部自动跳过。')}<br><br>${i18n('跳过原因：')}${commodityDeleteSkipReason(impact)}`,
                        'warning'
                    );
                    return;
                }
                if (skippedCount > 0) {
                    const deletableNames = commodityDeleteNames(impact.deletable_names, i18n('可删除商品'));
                    message.ask(
                        `${i18n('选中')} <b>${requestedCount} ${i18n('个商品')}</b>，${i18n('将永久删除其中')} <b>${deletableCount} ${i18n('个')}</b>：${deletableNames}<br><br>${i18n('另外')} <b>${skippedCount} ${i18n('个无法删除的商品会自动跳过')}</b>。<br>${i18n('跳过原因：')}${commodityDeleteSkipReason(impact)}<br><br>${i18n('删除后无法恢复。')}`,
                        done,
                        i18n('确认删除可删除商品'),
                        i18n('开始删除')
                    );
                    return;
                }
                const hasRecords = Number(impact.order_count || 0) > 0 || Number(impact.ticket_count || 0) > 0;
                message.ask(
                    `${i18n('将永久删除')} <b>${Number(impact.commodity_count || list.length)} ${i18n('个商品')}</b>：${names}`
                    + `<br><br>${i18n('以及其名下全部关联数据：')}${detail}`
                    + (hasRecords ? `<br><br><b style="color:#d33">${i18n('其中订单与工单属于经营记录，删除后账单统计将无法追溯。')}</b>` : '')
                    + `<br><br>${i18n('此操作无法恢复。')}`,
                    done,
                    i18n('确认永久删除商品'),
                    i18n('确认删除')
                );
            },
            error: res => controllerActive && message.error(res?.msg || i18n('无法计算商品删除影响，已阻止删除')),
            fail: () => controllerActive && message.error('网络异常，无法预览商品删除影响，已阻止删除')
        });
    };
    const commodityNameFields = (assign = {}) => {
        const languages = [
            {field: 'name', label: '简体中文'},
            {field: 'name_zh_tw', label: '繁体中文'},
            {field: 'name_en', label: '英文'},
            {field: 'name_ru', label: '俄语'},
            {field: 'name_vi', label: '越南语'}
        ];
        return [{
            title: '商品名称（五种语言）', name: 'name', type: 'custom', labelPosition: 'top', required: true, preserveLiteral: true,
            complete: (form, dom) => {
                const id = field => form.getUnique() + '-title-' + field;
                const stale = Array.isArray(assign.name_translation_stale) ? assign.name_translation_stale : [];
                const draft = Array.isArray(assign.name_translation_draft) ? assign.name_translation_draft : [];
                const tabs = languages.map(({field, label}, index) => `<button type="button" role="tab" id="${id(field)}-tab" aria-controls="${id(field)}-panel" aria-selected="${index === 0}" tabindex="${index === 0 ? 0 : -1}" data-title-tab="${field}" class="btn btn-sm ${index === 0 ? 'btn-primary' : 'btn-light'}" style="flex:0 0 auto;white-space:nowrap;padding:8px 12px">${label}</button>`).join('');
                const panels = languages.map(({field, label}, index) => {
                    const review = stale.includes(field) || draft.includes(field);
                    const state = stale.includes(field) ? '简体中文名称已更新，请核对本语言名称后再采用。'
                        : draft.includes(field) ? '此语言名称尚未确认，请核对后采用。' : index === 0 ? '必填，最多255个字符。' : '选填，留空时使用简体中文名称。';
                    const submit = index === 0 || (!assign.id && !review && String(assign[field] ?? '').trim() !== '');
                    return `<div role="tabpanel" id="${id(field)}-panel" aria-labelledby="${id(field)}-tab" data-title-panel="${field}" style="display:${index === 0 ? 'block' : 'none'};margin-top:12px">
                        <label for="${id(field)}" class="d-block mb-2 fw-semibold">${label}商品名称${index === 0 ? ' *' : ''}</label>
                        <input id="${id(field)}" ${submit ? `name="${field}"` : ''} data-title-field="${field}" class="layui-input" type="text" maxlength="255" value="${escapeAttr(assign[field] ?? '')}" placeholder="请输入${label}商品名称${index === 0 ? '' : '，留空使用简体中文'}" autocomplete="off">
                        <small class="text-muted d-block mt-2" data-title-state="${field}">${state}</small>
                        ${index === 0 ? '' : `<button type="button" class="btn btn-sm btn-light-primary mt-2" data-title-confirm="${field}" style="${review ? '' : 'display:none'}">核对并采用此名称</button>`}
                    </div>`;
                }).join('');
                dom.html(`<div role="tablist" aria-label="商品名称语言" style="display:flex;gap:8px;overflow-x:auto;padding-bottom:4px">${tabs}</div>${panels}<small class="text-muted d-block mt-3">五种语言分别填写；仅修改或确认过的外语名称会保存。清空后保存可删除该语言名称。</small>`);
                const activate = field => {
                    dom.find('[data-title-tab]').each(function () {
                        const active = this.dataset.titleTab === field;
                        $(this).attr({'aria-selected': String(active), tabindex: active ? 0 : -1})
                            .toggleClass('btn-primary', active).toggleClass('btn-light', !active);
                    });
                    dom.find('[data-title-panel]').each(function () { $(this).toggle(this.dataset.titlePanel === field); });
                };
                const adopt = field => {
                    dom.find(`[data-title-field="${field}"]`).attr('name', field);
                    dom.find(`[data-title-state="${field}"]`).text('此语言名称将在保存后生效；留空则使用简体中文名称。');
                    dom.find(`[data-title-confirm="${field}"]`).hide();
                };
                dom.on('click', '[data-title-tab]', function () { activate(this.dataset.titleTab); });
                dom.on('keydown', '[data-title-tab]', function (event) {
                    const current = languages.findIndex(language => language.field === this.dataset.titleTab);
                    let next;
                    if (event.key === 'ArrowRight') next = (current + 1) % languages.length;
                    else if (event.key === 'ArrowLeft') next = (current + languages.length - 1) % languages.length;
                    else if (event.key === 'Home') next = 0;
                    else if (event.key === 'End') next = languages.length - 1;
                    else return;
                    event.preventDefault();
                    activate(languages[next].field);
                    dom.find(`[data-title-tab="${languages[next].field}"]`).trigger('focus');
                });
                dom.on('input', '[data-title-field]', function () {
                    const field = this.dataset.titleField;
                    if (field !== 'name') adopt(field);
                    else if (String(this.value) !== String(assign.name ?? '')) {
                        languages.slice(1).forEach(({field}) => {
                            if (dom.find(`[data-title-field="${field}"]`).attr('name')) return;
                            dom.find(`[data-title-state="${field}"]`).text('简体中文名称已修改，请核对本语言名称后再采用。');
                            dom.find(`[data-title-confirm="${field}"]`).show();
                        });
                    }
                });
                dom.on('click', '[data-title-confirm]', function () { adopt(this.dataset.titleConfirm); });
                return {destroy: () => dom.off()};
            }
        }, ...languages.slice(1).map(({field}) => ({
            // Declare serializer fields without creating a second input. Untouched editions have no name attribute.
            name: field, type: 'custom', hide: true, preserveLiteral: true
        }))];
    };
    const modal = (title, assign = {}) => {
        if (!controllerActive) return;

        //标签入库是 [{text,color}]，attribute 组件认的是 [{name,value}]，这里做一次转换
        try {
            const raw = typeof assign?.tags === 'string' ? JSON.parse(assign.tags || '[]') : (assign?.tags || []);
            assign = Object.assign({}, assign, {
                tags: JSON.stringify((raw || []).map(t => ({name: t.text ?? t.name ?? '', value: t.color ?? t.value ?? ''})))
            });
        } catch (e) {
            assign = Object.assign({}, assign, {tags: '[]'});
        }

        const owner = 0;

        component.popup({
            drawer: true,          // content-heavy product form → open as a right-side drawer
            fitTabs: true,         // 标签页多到一行放不下时自动加宽（插件还会往这里塞标签页）
            submit: '/admin/api/commodity/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "商品分类",
                            name: "category_id",
                            type: "treeSelect",
                            placeholder: "请选择商品分类",
                            dict: `category->owner=${owner},id,name,pid&tree=true`,
                            required: true,
                            parent: false
                        },
                        {
                            title: "商品图标",
                            name: "cover",
                            type: "image",
                            placeholder: "上传商品图标",
                            previewSize: 96,
                            uploadUrl: '/admin/api/upload/send?preset=product-cover',
                            photoAlbumUrl: '/admin/api/upload/get',
                            tips: "建议上传 512×512 的正方形 PNG/WebP 图标；系统会居中压缩、保留透明背景，不放大小图。",
                            required: true
                        },
                        ...commodityNameFields(assign),
                        {
                            title: "零售价（人民币 CNY）",
                            name: "price",
                            type: "input",
                            inputmode: "decimal",
                            enterkeyhint: "next",
                            placeholder: "请输入人民币零售价",
                            tips: "以人民币 CNY 填写零售价，0=免费。",
                            required: true
                        },
                        {
                            title: "渠道单价（人民币 CNY）",
                            name: "channel_price",
                            type: "input",
                            inputmode: "decimal",
                            placeholder: "请输入人民币渠道价，留空关闭",
                            tips: "以人民币 CNY 填写渠道单价，留空关闭。"
                        },
                        {name: "user_price", type: "input", hide: true, default: 0},
                        {
                            title: "成本价",
                            name: "factory_price",
                            type: "input",
                            inputmode: "decimal",
                            enterkeyhint: "next",
                            tips: "用来统计利润，请填写商品的真实成本价。",
                            placeholder: "成本价"
                        },
                        {title: "排序", name: "sort", type: "input", inputmode: "numeric", enterkeyhint: "done", placeholder: "排序，越小越靠前"},
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                        {
                            title: "库存显示",
                            name: "inventory_hidden",
                            type: "switch",
                            text: "显示库存充足",
                            default: 1,
                            tips: "开启后，前台不显示具体库存数量，统一显示“库存充足”；实际库存扣减和售罄判断不受影响。"
                        },
                        {
                            title: "基础展示销量",
                            name: "display_sales",
                            type: "input",
                            inputmode: "numeric",
                            placeholder: "例如 3000；留空只显示实际售出件数",
                            tips: "展示销量 = 基础值 + 展示增长量 + 实际已付款件数。修改基础值会重新起算；留空关闭展示增长。绿色近1小时售出只统计真实付款。"
                        },
                        {
                            title: "销量增长周期",
                            name: "display_heat_period",
                            type: "radio",
                            default: 0,
                            dict: [
                                {id: 0, name: "不增长"},
                                {id: 1, name: "每小时"},
                                {id: 2, name: "每天"}
                            ],
                            tips: "随机增长请选择“每小时”；只设置基础值时选择“不增长”。按保存时间起算，修改增长规则会保留已累计的展示增长。"
                        },
                        {
                            title: "随机增长",
                            name: "display_heat_random",
                            type: "switch",
                            default: 0,
                            text: "每小时随机增加",
                            tips: "开启后每小时随机增加0到下方上限之间的展示数量，并受每日上限限制。关闭时兼容原来的固定增量。"
                        },
                        {
                            title: "每小时上限 / 固定增量",
                            name: "display_heat_step",
                            type: "input",
                            inputmode: "numeric",
                            default: 0,
                            placeholder: "例如 5",
                            tips: "随机增长时，这里是每小时最多增加多少；关闭随机时，这里是每周期固定增加多少。启用增长须填正整数。"
                        },
                        {
                            title: "每日增长上限",
                            name: "display_heat_daily_cap",
                            type: "input",
                            inputmode: "numeric",
                            default: 0,
                            placeholder: "例如 30",
                            tips: "随机增长必须填写正整数。每24小时最多增加此数量，从保存设置时刻起算，不按访客所在地区计时。"
                        },
                        {
                            title: "商品标签",
                            name: "tags",
                            type: "attribute",
                            allowEmpty: true,               //商品可以不设标签，允许一行不留
                            sortable: true,                 //可拖动排序，顺序即前台展示顺序
                            namePlaceholder: "标签文字，如：热销",
                            valueDict: [
                                {id: "red", name: "红色"},
                                {id: "orange", name: "橙色"},
                                {id: "green", name: "绿色"},
                                {id: "cyan", name: "青色"},
                                {id: "blue", name: "蓝色"},
                                {id: "purple", name: "紫色"},
                                {id: "pink", name: "粉色"},
                                {id: "gray", name: "灰色"}
                            ],
                            tips: "显示在商品列表卡片上，最多 5 个，每个最多 10 字；可拖动左侧手柄调整顺序"
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-truck") + i18n(" 发货设置"),
                    form: [
                        {
                            title: "发货方式",
                            name: "delivery_way",
                            type: "radio",
                            placeholder: "请选择",
                            dict: "_commodity_delivery_way",
                            default: 0,
                            required: true,
                            change: (_, __) => {
                                if (__ == 1) {
                                    _.show("delivery_message");
                                    _.hide("delivery_auto_mode");
                                    _.show("stock");
                                } else {
                                    _.hide("delivery_message");
                                    _.show("delivery_auto_mode");
                                    _.hide("stock");
                                }
                            },
                            complete: (_, __) => {
                                _.triggerOtherPopupChange("delivery_way", __);
                            }
                        },
                        {
                            title: "卡密排序",
                            name: "delivery_auto_mode",
                            type: "radio",
                            dict: "_commodity_delivery_auto_mode",
                            default: 0,
                            hide: true
                        },
                        {
                            title: "虚拟库存",
                            name: "stock",
                            type: "number",
                            placeholder: "虚拟库存数量",
                            default: 10000000,
                            tips: "虚拟库存，每购买1次，则减1，直到为0，商品就会已售罄",
                            hide: true
                        },
                        {
                            title: "发货信息",
                            name: "delivery_message",
                            type: "textarea",
                            placeholder: "手动发货信息，可以是一些固定的卡密或者软件下载链接等..",
                            height: 100,
                            hide: true
                        },
                        {
                            title: "发货留言",
                            name: "leave_message",
                            type: "textarea",
                            placeholder: "当用户购买商品后，该留言会显示在订单中",
                            height: 80,
                            tips: "当用户购买商品后，该留言会显示在订单中"
                        },
                        {
                            title: "联系方式",
                            name: "contact_type",
                            type: "radio",
                            dict: "_contact_type",
                            default: 0,
                            required: true
                        },
                        {
                            title: "邮件发送",
                            name: "send_email",
                            type: "switch",
                            text: "启用",
                            tips: "用户购买商品后，会将卡密信息发送至邮箱，仅联系方式为邮箱状态下有效。"
                        },
                        {
                            title: "查询密码",
                            name: "password_status",
                            type: "switch",
                            text: "启用",
                            tips: "开启后，下单时需要设置查询订单的密码，更强的保护用户隐私"
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-pen-field") + i18n(" 下单表单"),
                    form: [
                        {
                            name: "widget",
                            type: "widget",
                            height: 660
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-circle-info") + i18n(" 商品介绍"),
                    form: [
                        {
                            title: false,
                            name: "description",
                            type: "editorv2",
                            placeholder: "介绍一下你的商品..",
                            required: true,
                            uploadUrl: '/admin/api/upload/send',
                        },
                    ]
                },
                {
                    name: util.icon("fa-duotone fa-regular fa-shop-lock") + i18n(" 购买限制"),
                    form: [
                        {
                            title: "最低购买数量",
                            name: "minimum",
                            type: "input",
                            inputmode: "numeric",
                            enterkeyhint: "next",
                            tips: "单次最低购买数量，0=不限制，默认0",
                            default: 0,
                            placeholder: "单次最低购买数量"
                        },
                        {
                            title: "最大购买数量",
                            name: "maximum",
                            type: "input",
                            inputmode: "numeric",
                            enterkeyhint: "next",
                            tips: "单次最大购买数量，0=不限制，默认0",
                            default: 0,
                            placeholder: "单次最大购买数量"
                        },
                        {
                            title: "卡密预选",
                            name: "draft_status",
                            type: "switch",
                            text: "启用",
                            tips: "顾名思义，意思就是顾客在购买时，可以预先选择想要购买的那个卡密，一般针对于出售游戏账号等用途。",
                            change: (_, __) => {
                                if (__ == 1) {
                                    _.show('draft_premium');
                                } else {
                                    _.hide('draft_premium');
                                }
                            },
                            complete: (_, __) => {
                                _.triggerOtherPopupChange("draft_status", __);
                            }
                        },
                        {
                            title: "预选加价",
                            name: "draft_premium",
                            type: "input",
                            inputmode: "decimal",
                            enterkeyhint: "next",
                            tips: "如果用户使用预选功能，则会加价购买",
                            placeholder: "加价金额",
                            hide: true
                        },
                        {
                            title: "会员限购",
                            name: "purchase_count",
                            type: "input",
                            inputmode: "numeric",
                            enterkeyhint: "done",
                            placeholder: "0代表不限制",
                            tips: "0代表不限制，如果限制了购买数量，那么用户必须登录才能购买",
                            default: 0,
                        },
                        {
                            title: "隐藏商品",
                            name: "hide",
                            type: "switch",
                            text: "是",
                            default: 0,
                            tips: "开启后，首页不显示该商品。"
                        },
                        {
                            title: "首页推荐",
                            name: "recommend",
                            type: "switch",
                            text: "上推荐",
                            tips: "该功能需要在'网站设置'->'其他设置'中开启首页推荐功能才会显示"
                        }
                    ]
                },
            ],
            assign: assign,
            autoPosition: true,
            content: {
                css: {
                    height: "auto",
                    overflow: "inherit"
                }
            },
            height: "auto",
            width: "960px",
            done: () => {
                if (controllerActive && table) table.refresh();
            }
        });
    }

    const uploadCard = (commodityId) => {
        if (!controllerActive) return;
        let skuRevision = 0;
        const createForms = [];
        component.popup({
            submit: '/admin/api/card/save',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-folder-arrow-up") + i18n(" 上传卡密"),
                    form: [
                        {
                            title: false,
                            name: "commodity_id",
                            type: "input",
                            default: commodityId,
                            hide: true,
                            complete: (_, __) => {
                                const revision = ++skuRevision;
                                _.setRadio("race_get_mode", 0, true);
                                _.setInput("race_input", "");

                                _.hide("race");
                                _.hide("race_input");
                                _.clearComponent("race");
                                _.hide("race_get_mode");
                                createForms.forEach(k => _.removeForm(k));
                                createForms.length = 0;

                                util.get(`/admin/api/card/sku?commodityId=${commodityId}`, data => {
                                    if (!controllerActive || _.isDestroyed || revision !== skuRevision) return;
                                    if (!util.isEmptyOrNotJson(data?.category)) {
                                        let i = 0;
                                        for (const cKey in data.category) {
                                            _.addRadio("race", cKey, cKey, i === 0);
                                            i++;
                                        }
                                        _.show("race");
                                        _.show(`race_get_mode`);
                                    }
                                    if (!util.isEmptyOrNotJson(data?.sku)) {
                                        for (const sKey in data.sku) {
                                            let dict = [];
                                            for (const sk in data.sku[sKey]) {
                                                dict.push({id: sk, name: sk});
                                            }
                                            _.createForm({
                                                title: sKey,
                                                name: `sku.${sKey}`,
                                                type: "radio",
                                                dict: dict
                                            }, "race", "after");
                                            createForms.push(`sku-${sKey}`);
                                        }
                                    }
                                });

                            }
                        },
                        {
                            title: "种类获取方法",
                            name: "race_get_mode",
                            type: "radio",
                            dict: [{id: 0, name: "自动获取"}, {id: 1, name: "手动填写"}],
                            hide: true,
                            change: (_, __) => {
                                if (__ == 1) {
                                    _.hide("race");
                                    _.show("race_input");
                                } else {
                                    _.show("race");
                                    _.hide("race_input");
                                }
                            }
                        },
                        {
                            title: "商品种类",
                            name: "race_input",
                            type: "input",
                            placeholder: "请填写商品种类",
                            hide: true
                        },
                        {
                            title: "商品种类",
                            name: "race",
                            type: "radio",
                            placeholder: "商品类别，一般你用不着，而且不懂不要乱填哦，想用请查看说明文档",
                            hide: true
                        },
                        {
                            title: "备注信息",
                            name: "note",
                            type: "input",
                            placeholder: "备注信息(可空)，方便查询某次添加的卡密"
                        },
                        {
                            title: "卡密类型",
                            name: "card_type",
                            type: "radio",
                            dict: [
                                {id: 0, name: "普通卡密"},
                                {id: 1, name: "账号/预告"}
                            ],
                            change: (form, val) => {
                                if (val == 0) {
                                    form.show("general_card");
                                    form.hide("account_card");
                                } else {
                                    form.hide("general_card");
                                    form.show("account_card");
                                }
                            }
                        },
                        {
                            title: false,
                            name: "general_card",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="uc-cardtip">
          <p>${i18n('一行一个库存卡密，内容随意。买家购买后直接获得该行内容。')}</p>
          <div class="uc-cardtip__label">${i18n('示例')}</div>
          <pre class="uc-cardtip__code">ABCDEF-GHIJK-LMNOP
VIP-2025-0821-XYZ</pre>
        </div>`);
                            }
                        },
                        {
                            title: false,
                            hide: true,
                            name: "account_card",
                            type: "custom",
                            complete: (form, dom) => {
                                dom.html(`<div class="uc-cardtip">
          <p>${i18n('一行一个，必须使用')} <code>║</code> ${i18n('分隔，结构为：')}<b>${i18n('卡密本体')} ║ ${i18n('预告信息')} ║ ${i18n('自选加价金额')}(${i18n('可选')}) ║ ${i18n('自选加价成本')}(${i18n('可选')})</b></p>
          <ul class="uc-cardtip__legend">
            <li><span class="a-badge a-badge-dark">${i18n('卡密本体')}</span><span>${i18n('买家付款后实际获得的完整内容')}</span></li>
            <li><span class="a-badge a-badge-success">${i18n('预告信息')}</span><span>${i18n('买家下单时可见，用于自选')}</span></li>
            <li><span class="a-badge a-badge-warning">${i18n('自选加价金额')}</span><span>${i18n('选填，不写默认为')} 0</span></li>
            <li><span class="a-badge a-badge-primary">${i18n('自选加价成本')}</span><span>${i18n('选填，不写默认为')} 0</span></li>
          </ul>
          <div class="uc-cardtip__label">${i18n('示例')}</div>
          <pre class="uc-cardtip__code">${i18n('账号')}:testname--${i18n('密码')}:testpassword123║${i18n('大区')}:${i18n('神境之地')}--${i18n('等级')}:100║5.5║2.5
ACC_US_12M_9F2K-7QPA-88XZ║${i18n('地区')}:${i18n('美区')}·${i18n('时长')}:12${i18n('个月')}║20║8
ACC_JP_6M_0KLD-22MM-PP31║${i18n('地区')}:${i18n('日区')}·${i18n('时长')}:6${i18n('个月')}</pre>
          <div class="uc-cardtip__warn"><span class="material-icons-outlined">warning_amber</span><span>${i18n('必须使用特殊符号')} <strong>║</strong>（U+2551），${i18n('不要用普通竖线')} |</span></div>
        </div>`);
                            }
                        },
                        {
                            title: "卡密信息",
                            name: "secret",
                            type: "textarea",
                            placeholder: "卡密信息，一行一个",
                            preserveLiteral: true,
                            height: 200,
                            required: true
                        },
                        {
                            title: "去除重复",
                            name: "unique",
                            type: "switch",
                            text: "启用（保持数据唯一，会占用CPU资源）"
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "680px",
            done: () => {
                if (controllerActive && table) table.refresh();
            }
        });
    }

    // 拖动排序：公共实现在 drag-sort.js（与分类管理共用，行为一模一样）。电脑版拖手柄，手机版长按整张卡片。
    // 商品列表分页且可筛选：后端只在「这一页商品当前占着的位置」里重排，其它商品原地不动，所以筛选、翻页时都能拖。
    const dragEnabled = Boolean(window.MdTableDragSort);
    let dragSort = null;

    table = new Table("/admin/api/commodity/data", "#commodity-table");
    table.setUpdate("/admin/api/commodity/save");
    table.setColumns([
        {checkbox: true}
        , {
            field: 'name', title: '商品', formatter: (val, item) => {
                const cover = item.cover
                    ? `<img src="${escapeAttr(item.cover)}" data-id="${item.id}" class="render-image md-commodity-cell__cover" alt="${i18n('放大图片')}">`
                    : `<span class="md-commodity-cell__cover md-commodity-cell__cover--ph"><i class="fa-duotone fa-regular fa-image"></i></span>`;
                const path = Array.isArray(item.category_path) ? item.category_path : [];
                const sep = `<span class="md-commodity-cell__cat-sep">›</span>`;
                const cat = path.length
                    ? `<span class="md-commodity-cell__cat">${path.map(s => `<span class="md-commodity-cell__cat-seg">${s}</span>`).join(sep)}</span>`
                    : '';
                return `<div class="md-commodity-cell">${cover}<div class="md-commodity-cell__text"><span class="md-commodity-cell__name">${escapeHtml(val)}</span>${cat}</div></div>`;
            }
        }
        , {
            field: 'card_count', title: '库存', class: "nowrap", formatter: function (val, item) {
                if (item.shared_id > 0) {
                    return '-';
                }
                if (item.delivery_way == 0) {
                    const count = `<a class='sku-stock-view' data-id='${item.id}' title='${i18n('点击查看')}SKU${i18n('详细库存')}' href='javascript:void(0);' style='color: var(--md-primary); font-weight: 600;'>${item.card_count}</a>`;
                    if (mobileAdminEnabled()) return count;
                    return count + ` <a class='add-card' data-id='${item.id}' style='color: green;' href='javascript:void(0);'>${i18n('加卡')}</a>`;
                }
                return item.stock;
            }
        }
        , {
            field: 'inventory_hidden', title: '库存显示', type: 'switch', text: '充足|数量', reload: true, class: 'nowrap'
        }
        , {
            field: 'display_sales_total', title: '当前展示销量', class: 'nowrap', formatter: value => value == null
                ? '<span class="text-muted">0</span>'
                : `<strong style="color:var(--md-primary);font-variant-numeric:tabular-nums">${Number(value).toLocaleString()}</strong>`
        }
        , {field: 'price', title: '零售价（CNY）'}
        , {field: 'channel_price', title: '渠道价（CNY）', formatter: value => value === null || value === '' ? '—' : format.amountRemoveTrailingZeros(value)}
        , {field: 'order_all_amount', title: '累计成交额'}
        , ...(dragEnabled ? [MdTableDragSort.column()] : [])
        , {field: 'sort', title: '排序'}
        , {
            field: 'status', title: '状态', type: "switch", text: "上架|下架", reload: true, class: "nowrap"
        },
        {
            field: 'recommend', title: '推荐', type: "switch", text: "已推荐|未推荐", reload: true, class: "nowrap"
        },
        {
            field: 'operation', class: "action-col", title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + i18n("修改商品"), row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-copy',
                    class: "text-warning",
                    click: (event, value, row, index) => {
                        const clone = {...row};
                        delete clone.id;
                        delete clone.shared_code;
                        delete clone.code;
                        delete clone.shared_id;
                        delete clone.shared;
                        modal(util.icon("fa-duotone fa-regular fa-copy me-1") + i18n("克隆商品"), clone);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        confirmCommodityDelete([row.id], row.name, () => {
                            util.post('/admin/api/commodity/del', {list: [row.id]}, res => {
                                if (!controllerActive || !table) return;
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-key text-success',
                    class: 'admin-mobile-operation-only text-success',
                    title: '添加卡密',
                    show: row => mobileAdminEnabled() && Number(row.shared_id || 0) <= 0 && Number(row.delivery_way) === 0,
                    click: (event, value, row) => uploadCard(row.id)
                }
            ]
        },
    ]);

    // 双击「商品」列 → MUI 详情弹窗；hover 提示「双击查看详细信息」（取代原「更多信息」按钮列）
    table.setColumnDetail({
        column: 'name',
        trigger: 'dblclick',
        header: false,
        title: (row) => row.name,
        fields: [
        {field: 'id', title: '商品ID'},
        {
            field: 'card_success_count', title: '已出售'
        },
        {
            field: 'display_sales_total', title: '当前展示销量'
        },
        {
            field: 'delivery_way', title: '发货方式', dict: "_commodity_delivery_way"
        },
        {
            field: 'delivery_auto_mode', title: '出库顺序', dict: "_commodity_delivery_auto_mode"
        },
        {
            field: 'contact_type', title: '联系方式', dict: "_contact_type"
        },
        {
            field: 'password_status', title: '订单密码', dict: "_commodity_api_status"
        },
        {
            field: 'draft_status', title: '预选卡密', dict: "_commodity_api_status"
        },
        {
            field: 'draft_premium', title: '预选加价'
        },
        {
            field: 'inventory_hidden', title: '隐藏库存', dict: "_commodity_api_status"
        },
        {
            field: 'send_email', title: '发送邮件', dict: "_commodity_api_status"
        },
        {
            field: 'purchase_count', title: '限购数量(需登录)'
        },
        {
            field: 'minimum', title: '单次最低购买数量'
        },
        {
            field: 'hide', title: '隐藏商品', dict: "_commodity_api_status"
        },
        {
            field: 'create_time', title: '创建时间'
        },
        ]
    });

    table.setSearch([
        {title: "商品分类", name: "equal-category_id", type: "treeSelect", dict: "category->owner=0,id,name,pid&tree=true", search: true},
        {title: "商品名称", name: "search-name", type: "input"},
    ]);
    table.setState("status", "_commodity_status");

    table.onComplete(() => dragSort?.sync());
    if (dragEnabled) {
        dragSort = MdTableDragSort.attach({
            table,
            selector: '#commodity-table',
            namespace: namespace + 'Drag',
            url: '/admin/api/commodity/reorder',
            isActive: () => controllerActive,
            hint: '按住拖动，调整商品顺序',
            singleText: '这一页只有这一个商品，不需要排序',
            blockedReason: () => {
                // 按其它列排过序时，列表显示的不是实际顺序，拖完会把这个顺序当成实际顺序存进去
                const sortField = String(table?.queryParams?.sort_field ?? '');
                if (sortField !== '' && !(sortField === 'sort' && String(table?.queryParams?.sort_rule ?? '') === 'asc')) {
                    return '列表正按其它列排序，显示的不是实际顺序，请刷新页面恢复默认排序后再拖动';
                }
                // 后端单次最多处理 500 个商品
                if ((table?.getRows?.() || []).length > 500) {
                    return '每页超过 500 个商品时不能拖动排序，请把每页条数调小';
                }
                return '';
            },
            // 卡片上显示商品主图和名称（名称单元格里还有分类路径，不能整格取文本）
            describe: tr => {
                const cell = tr.querySelector('.md-commodity-cell');
                return {
                    name: (cell?.querySelector('.md-commodity-cell__name')?.textContent || '').trim(),
                    icon: cell?.querySelector('img.md-commodity-cell__cover')?.getAttribute('src') || ''
                };
            }
        });
    }

    table.render();


    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> ${i18n('添加商品')}`);
    });

    $('.delist').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品进行操作！"));
            return;
        }

        message.ask("您确定要下架选中的商品吗？", () => {
            util.post("/admin/api/commodity/status", {list: data, status: 0}, res => {
                if (!controllerActive || !table) return;
                message.success("全部下架完成");
                table.refresh();
            });
        });
    });


    $('.listed').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品进行操作！"));
            return;
        }
        message.ask("您确定要上架选中的商品吗？", () => {
            util.post("/admin/api/commodity/status", {list: data, status: 1}, res => {
                if (!controllerActive || !table) return;
                message.success("全部上架完成");
                table.refresh();
            });
        });
    });


    $('.btn-app-del').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品进行操作！"));
            return;
        }
        confirmCommodityDelete(data, `${data.length} ${i18n('个商品')}`, () => {
            util.post("/admin/api/commodity/del", {list: data}, res => {
                if (!controllerActive || !table) return;
                showCommodityBatchDeleteResult(res);
                table.refresh();
            });
        });
    });


    $('.handle').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品进行操作！"));
            return;
        }

        let join = data.join(",");
        const useMobileBatchSettings = mobileAdminEnabled();
        let submitting = false;
        const submitMobileBatchSettings = (formData, index) => {
            if (submitting) return;
            const changes = batchSettingDefinitions.filter(item => {
                const value = String(formData[item.name] ?? 'keep');
                return value === '0' || value === '1';
            });
            if (!changes.length) {
                message.warning('请至少选择一项需要修改的设置');
                return;
            }
            const summary = changes.map(item => {
                const enabled = String(formData[item.name]) === '1';
                return `<li><b>${item.title}</b> → ${enabled ? i18n('开启') : i18n('关闭')}</li>`;
            }).join('');
            message.ask(
                `${i18n('将修改')} <b>${data.length} ${i18n('个商品')}</b>，${i18n('仅变更以下项目，其他设置保持原值：')}<ul style="text-align:left;margin:14px 0 0 22px">${summary}</ul>`,
                () => {
                    submitting = true;
                    util.post('/admin/api/commodity/fastEnable', formData, res => {
                        submitting = false;
                        if (!controllerActive || !table) return;
                        layer.close(index);
                        const count = Number(res.data?.selected_count || data.length);
                        message.success(`${i18n('已完成')} ${count} ${i18n('个商品的批量设置')}`);
                        table.refresh();
                    }, error => {
                        submitting = false;
                        if (!controllerActive) return;
                        message.alert(error.msg || i18n('批量设置失败'), 'error');
                    }, () => {
                        submitting = false;
                        if (!controllerActive) return;
                        message.error('网络错误，请稍后重试');
                    });
                },
                i18n('确认批量设置'),
                i18n('确认应用')
            );
        };

        component.popup({
            submit: useMobileBatchSettings ? submitMobileBatchSettings : '/admin/api/commodity/fastEnable',
            tab: [
                {
                    name: util.icon("fa-duotone fa-regular fa-sliders") + i18n(" 批量设置"),
                    form: [
                        {
                            title: "",
                            name: "list",
                            type: "input",
                            hide: true,
                            default: join
                        },
                        ...batchSettingDefinitions.map(batchSettingForm)
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            width: "320px",
            maxmin: false,
            done: () => {
                if (controllerActive && table) table.refresh();
            }
        });
    });

    $(document).off('click' + namespace, '.add-card').on('click' + namespace, '.add-card', function () {
        const id = $(this).data("id");
        uploadCard(id);
    });

    //SKU库存明细弹窗：动态拉取该商品按 类别/SKU 组合的库存统计
    //注意：util.get 的回调入参已经是 res.data 本身(与 util.post 不同)
    const openSkuStock = (commodityId) => {
        util.get(`/admin/api/card/skuStock?commodityId=${commodityId}`, data => {
            if (!controllerActive) return;
            const list = data?.list ?? [];
            if (!list.length) {
                layer.msg(i18n("该商品还没有入库卡密"));
                return;
            }
            const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            let rows = '';
            list.forEach(item => {
                let sku = item.sku;
                if (typeof sku === 'string') {
                    try {
                        sku = JSON.parse(sku);
                    } catch (e) {
                        sku = null;
                    }
                }
                let skuText = '-';
                if (sku && typeof sku === 'object') {
                    skuText = Object.keys(sku).map(k => `${k}: ${sku[k]}`).join(' / ') || '-';
                }
                const race = (item.race && item.race !== '-') ? item.race : '-';
                rows += `<tr><td>${esc(race)}</td><td>${esc(skuText)}</td><td style="color:var(--md-success);font-weight:600">${item.unsold}</td><td>${item.locked}</td><td>${item.sold}</td><td>${item.total}</td></tr>`;
            });
            layer.open({
                type: 1,
                title: `${util.icon("fa-duotone fa-regular fa-boxes-stacked")} SKU${i18n('库存')} - ${esc(String(data?.name ?? '').replace(/<[^>]*>/g, ''))}`,
                area: util.isPc() ? '640px' : ["100%", "100%"],
                shadeClose: true,
                content: `<div style="padding:16px;overflow:auto"><table class="table align-middle"><thead><tr><th>${i18n('类别')}</th><th>SKU</th><th>${i18n('未售')}</th><th>${i18n('锁定')}</th><th>${i18n('已售')}</th><th>${i18n('合计')}</th></tr></thead><tbody>${rows}</tbody></table></div>`
            });
        });
    };

    $(document).off('click' + namespace, '.sku-stock-view').on('click' + namespace, '.sku-stock-view', function () {
        openSkuStock($(this).data("id"));
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        $('.btn-app-create, .delist, .listed, .btn-app-del, .handle').off(namespace);
        $(document).off(namespace);
        dragSort?.destroy();
        dragSort = null;
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdTradeCommodityDestroy === destroy) delete window.__mdTradeCommodityDestroy;
    }

    window.__mdTradeCommodityDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);

}();
