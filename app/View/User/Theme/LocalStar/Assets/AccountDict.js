const _Dict = new class BuyerDictionary extends _DictUtil {
    constructor() {
        super();
        this.dictUrl = null;
        this.data = {
            "_order_status": [
                {id: 1, name: format.badge(i18n("已支付"), "a-badge-success")},
                {id: 0, name: format.badge(i18n("未支付"), "a-badge-danger")}
            ],
            "_order_delivery_status": [
                {id: 1, name: format.badge(i18n("已发货"), "a-badge-success")},
                {id: 0, name: format.badge(i18n("未发货"), "a-badge-danger")}
            ]
        };
    }
    get(key) {
        return this.globalization(this.data[key] || []);
    }
    advanced(key, done = null) {
        const values = typeof key === 'object' && Array.isArray(key) ? key : (this.data[key] || []);
        if (done) done(this.globalization(values));
    }
};
