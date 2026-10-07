!function () {
    let table;
    const namespace = '.mdTradeCategoryController';
    let controllerActive = true;
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const confirmCategoryDelete = (rows, done) => {
        const selected = (Array.isArray(rows) ? rows : []).filter(Boolean);
        const ids = selected.map(row => Number(row.id)).filter(id => Number.isInteger(id) && id > 0);
        if (!ids.length) {
            message.error('没有可删除的分类');
            return;
        }
        const names = selected.slice(0, 4).map(row => escapeHtml(row.name || `ID ${row.id}`));
        const more = selected.length > names.length ? ` ${i18n('等')} ${selected.length} ${i18n('个分类')}` : '';
        util.post({
            url: '/admin/api/category/deleteImpact',
            data: {list: ids},
            done: res => {
                if (!controllerActive) return;
                const impact = res?.data || {};
                const n = v => escapeHtml(v ?? 0);
                const line = (label, value, unit, note) =>
                    `<div><b>${i18n(label)}</b>${n(value)} ${i18n(unit)}${note ? `<span style="opacity:.65;"> ${i18n(note)}</span>` : ''}</div>`;
                // 这些引用全部会被自动清理，不再是「阻止删除」的理由；弹窗只负责把代价说清楚
                const impactSummary = `<div style="text-align:left;line-height:1.8;">
                    <div><b>${i18n('所选分类：')}</b>${names.join('、') || i18n('当前所选分类')}${more}</div>
                    <div style="margin-top:10px;padding:10px 12px;border-radius:12px;background:rgba(127,127,127,.09);">
                        ${line('将删除分类：', impact.scope_count ?? impact.category_count, '个', (impact.descendant_count ?? 0) > 0 ? `（${i18n('含下级')} ${escapeHtml(impact.descendant_count)} ${i18n('个')}）` : '')}
                        ${line('连带删除商品：', impact.commodity_count, '个')}
                        ${line('连带删除订单：', impact.order_count, '笔')}
                        ${line('连带删除卡密：', impact.card_count, '张')}
                        ${line('连带删除优惠券：', impact.coupon_count, '张')}
                        ${line('自动解除商户分类映射：', impact.user_category_count, '条')}
                        ${line('自动清空网站默认分类引用：', impact.config_reference_count, '条')}
                    </div>`;
                const previewToken = String(impact.preview_token || '');
                if (!previewToken) {
                    message.error('服务器未返回有效的删除预览凭证，已阻止删除');
                    return;
                }
                Swal.fire({
                    title: selected.length > 1 ? `${i18n('确认删除')} ${selected.length} ${i18n('个所选分类')}` : i18n('确认删除分类'),
                    html: `${impactSummary}<div style="margin-top:10px;color:#d14343;">${i18n('分类连同其下级分类、分类内商品及这些商品的订单、工单、卡密、优惠券会被一并删除。预览凭证')} 3 ${i18n('分钟内有效，范围在此期间变化会要求重新预览；操作不可撤销。')}</div></div>`,
                    icon: 'warning',
                    showCancelButton: true,
                    cancelButtonText: i18n('取消'),
                    confirmButtonText: i18n('确认永久删除')
                }).then(result => {
                    if (result.isConfirmed === true || result.value === true) done(previewToken);
                });
            },
            error: res => message.error(res?.msg || i18n('无法计算删除影响，已阻止删除')),
            fail: () => message.error('网络异常，已阻止删除')
        });
    };

    if (typeof window.__mdTradeCategoryDestroy === 'function') window.__mdTradeCategoryDestroy();
    const confirmCategoryStatus = (rows, status, done, options = {}) => {
        const selected = (Array.isArray(rows) ? rows : []).filter(Boolean);
        const enabling = Number(status) === 1;
        if (!mobileAdminEnabled()) {
            if (options.desktopConfirm) message.ask(null, done); else done();
            return;
        }
        const names = selected.slice(0, 4).map(row => escapeHtml(row.name || `ID ${row.id}`));
        Swal.fire({
            title: enabling ? i18n('确认启用分类') : i18n('确认停用分类'),
            html: `<div style="text-align:left;line-height:1.8;">
                <div><b>${i18n('所选分类：')}</b>${names.join('、') || `${i18n('共')} ${selected.length} ${i18n('个分类')}`}</div>
                <div style="margin-top:10px;">${enabling
                    ? i18n('为保证层级完整，系统会同时启用所选分类尚未启用的上级分类。')
                    : i18n('系统会同时停用所选分类下的全部子分类，相关商品将不再通过这些分类展示。')}</div>
            </div>`,
            icon: enabling ? 'question' : 'warning',
            showCancelButton: true,
            cancelButtonText: i18n('取消'),
            confirmButtonText: enabling ? i18n('确认启用') : i18n('确认停用')
        }).then(result => {
            if (result.isConfirmed === true || result.value === true) done();
            else if (typeof options.cancel === 'function') options.cancel();
        });
    };
    const modal = (title, assign = {}) => {
        const ownerId = 0;
        component.popup({
            submit: '/admin/api/category/save',
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "父级分类",
                            name: "pid",
                            type: "treeSelect",
                            dict: `category->owner=${ownerId},id,name,pid&tree=true`,
                            placeholder: "父级分类，可不选",
                            parent: true,
                            clearToZero: true
                        },
                        {
                            title: "图标",
                            name: "icon",
                            type: "image",
                            placeholder: "请选择图标",
                            uploadUrl: '/admin/api/upload/send',
                            photoAlbumUrl: '/admin/api/upload/get',
                            height: 64,
                            required: true
                        },
                        {
                            title: "简体中文名称",
                            name: "name",
                            type: "input",
                            placeholder: "分类名称（简体中文，必填）",
                            required: true,
                            preserveLiteral: true,
                            tips: "访客按当前页面语言查看分类名称；未填写的译文显示简体中文名称。"
                        },
                        {
                            title: "繁体中文名称",
                            name: "name_zh_tw",
                            type: "input",
                            default: "",
                            preserveLiteral: true,
                            placeholder: "繁体中文名称（选填，留空显示简体中文）"
                        },
                        {
                            title: "英文名称",
                            name: "name_en",
                            type: "input",
                            default: "",
                            preserveLiteral: true,
                            placeholder: "英文分类名称（选填）"
                        },
                        {
                            title: "俄语名称",
                            name: "name_ru",
                            type: "input",
                            default: "",
                            preserveLiteral: true,
                            placeholder: "俄语分类名称（选填）"
                        },
                        {
                            title: "越南语名称",
                            name: "name_vi",
                            type: "input",
                            default: "",
                            preserveLiteral: true,
                            placeholder: "越南语分类名称（选填）"
                        },
                        {title: "排序", name: "sort", type: "input", placeholder: "值越小，排名越靠前哦~"},
                        {
                            title: "隐藏分类",
                            name: "hide",
                            type: "switch",
                            text: "是",
                            default: 0,
                            tips: "开启后，首页不显示该分类。"
                        },
                        {title: "状态", name: "status", type: "switch", text: "启用"},
                    ]
                },
            ],
            assign: assign,
            autoPosition: true,
            height: "auto",
            width: "680px",
            renderComplete: unique => {
                $('.' + unique + ' input[name="sort"]').attr({inputmode: 'numeric', autocomplete: 'off'});
                const names = $('.' + unique + ' input[name="name"], .' + unique + ' input[name="name_zh_tw"], .' + unique + ' input[name="name_en"], .' + unique + ' input[name="name_ru"], .' + unique + ' input[name="name_vi"]');
                names.attr({autocomplete: 'off', maxlength: 255});
            },
            done: () => {
                table.refresh();
            }
        });
    }

    // 拖动排序：公共实现在 drag-sort.js（分类管理、商品管理共用，行为保持一模一样）。
    // 电脑版拖「排序」列旁的手柄；手机版卡片列表长按整张卡片拖动（手柄列在手机版里自动隐藏）
    const dragEnabled = Boolean(window.MdTableDragSort);
    let dragSort = null;

    table = new Table("/admin/api/category/data", "#category-table");
    table.setUpdate(data => {
        const isStatus = Object.prototype.hasOwnProperty.call(data, 'status');
        const row = table.getRows().find(item => Number(item.id) === Number(data.id));
        const refresh = () => { if (controllerActive && table) table.refresh(true); };
        const submit = () => {
            const payload = isStatus
                ? {list: [data.id], status: Number(data.status)}
                : data;
            util.post({
                url: isStatus ? '/admin/api/category/status' : '/admin/api/category/save',
                data: payload,
                done: () => {
                    if (!controllerActive) return;
                    message.success('已更新 (｡•ᴗ-)');
                    refresh();
                },
                error: res => {
                    message.error(res?.msg || i18n('分类更新失败'));
                    refresh();
                },
                fail: () => {
                    message.error('网络异常，分类未更新');
                    refresh();
                }
            });
        };
        if (isStatus) {
            confirmCategoryStatus(row ? [row] : [], Number(data.status), submit, {cancel: refresh});
            return;
        }
        submit();
    });
    table.setTree(3);
    table.onComplete(() => dragSort?.sync());
    if (dragEnabled) {
        dragSort = MdTableDragSort.attach({
            table,
            selector: '#category-table',
            namespace: namespace + 'Drag',
            url: '/admin/api/category/reorder',
            tree: true,
            isActive: () => controllerActive,
            hint: '按住拖动，调整同级分类的顺序',
            singleText: '这一层级下只有这一个分类，不需要排序',
            // 按名称搜索、按状态筛选时，列表里的同级分类不完整，拖了会把没显示的那几个的顺序写乱
            blockedReason: () => {
                if (String(table?.queryParams?.['search-name'] ?? '').trim() !== '') {
                    return '正在按名称搜索，列表不完整，请先清空搜索再拖动排序';
                }
                if (String(table?.getState?.()?.value ?? '') !== '') {
                    return '正在按状态筛选，列表不完整，请切到「全部」再拖动排序';
                }
                return '';
            }
        });
    }
    table.setColumns([
        {checkbox: true},
        {field: 'icon', title: '', type: "image", style: "border-radius:25%;", width: 28},
        {
            field: 'name', title: '分类名称',
            // Category names are authored plain text in every language.
            formatter: value => escapeHtml(value)
        }
        , ...(dragEnabled ? [MdTableDragSort.column()] : [])
        //排序：拖动左侧手柄，或直接改数字（越小越前）。分类树始终按真实顺序展示，所以不再提供表头升降序切换
        , {field: 'sort', title: '排序(越小越前)', type: "input", reload: true}
        , {field: 'hide', title: '隐藏', type: 'switch', text: '隐藏|未隐藏'}
        , {
            field: 'status', title: '状态', type: "switch", text: "启用|停用", mobileConfirm: false
        },
        {
            field: 'operation', title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + i18n("修改分类"), row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can text-danger',
                    click: (event, value, row, index) => {
                        confirmCategoryDelete([row], previewToken => {
                            util.post('/admin/api/category/del', {list: [row.id], preview_token: previewToken}, res => {
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                }
            ]
        },
    ]);
    table.setSearch([
        {title: "分类名称", name: "search-name", type: "input"},
    ]);
    table.setState("status", "_common_status");

    //分类是树：分页会把父级不在同一页的子分类整行丢掉，本身也没有意义，全量展示
    table.disablePagination();
    table.render();


    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`<i class="fa-duotone fa-regular fa-circle-plus"></i> ${i18n('添加分类')}`);
    });

    $('.btn-app-del').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个商品分类进行操作！"));
            return;
        }

        confirmCategoryDelete(table.getSelections(), previewToken => {
            util.post("/admin/api/category/del", {list: data, preview_token: previewToken}, res => {
                message.success("删除成功")
                table.refresh();
            });
        });
    });

    $('.start').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个分类进行操作！"));
            return;
        }
        confirmCategoryStatus(table.getSelections(), 1, () => {
            util.post("/admin/api/category/status", {list: data, status: 1}, res => {
                message.success("启用成功");
                table.refresh();
            });
        }, {desktopConfirm: true});
    });

    $('.stop').off(namespace).on('click' + namespace, () => {
        let data = table.getSelectionIds();
        if (data.length == 0) {
            layer.msg(i18n("请至少勾选1个分类进行操作！"));
            return;
        }
        confirmCategoryStatus(table.getSelections(), 0, () => {
            util.post("/admin/api/category/status", {list: data, status: 0}, res => {
                message.success("停用成功");
                table.refresh();
            });
        }, {desktopConfirm: true});
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        dragSort?.destroy();
        dragSort = null;
        $('.btn-app-create, .btn-app-del, .start, .stop').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (window.__mdTradeCategoryDestroy === destroy) delete window.__mdTradeCategoryDestroy;
    }

    window.__mdTradeCategoryDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);


}();
