!function () {
    // Retire the old support timer when replacing a cached administrator shell.
    if (window.__adminTicketBadgeTimer) {
        clearInterval(window.__adminTicketBadgeTimer);
        delete window.__adminTicketBadgeTimer;
    }
    $(document).off('ticket:badge-refresh.admin');

    function _Pjax() {
        $(document).pjax('a[target!=_blank]', '#pjax-container', {fragment: '#pjax-container', timeout: 8000});
        $(document).on('pjax:send', function () {
            Loading.show();
            // 手机版:点菜单(pjax 导航)后自动收起侧栏抽屉；桌面态 aside 非 drawer-on,跳过
            var aside = document.querySelector('#kt_aside');
            if (aside && aside.classList.contains('drawer-on') && window.KTDrawer) {
                var drawer = KTDrawer.getInstance(aside);
                drawer && drawer.hide();
            }
        });
        $(document).on('pjax:complete', function () {
            Loading.hide();
        });
        $("a[target!=_blank]").click(function () {
            $('a[target!=_blank]').removeClass("active");
            $(this).addClass("active");
        });
    }

    _Pjax();
}();
