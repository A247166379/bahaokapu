<?php
declare(strict_types=1);
namespace App\Util;

/** Message locale comes from the order, never the asynchronous gateway request. */
final class OrderLocale
{
    public static function normalize(mixed $locale): string
    {
        return is_string($locale) && in_array($locale, ['zh-cn', 'zh-tw', 'en', 'ru', 'vi'], true) ? $locale : 'zh-cn';
    }
    public static function text(string $key, mixed $locale): string
    {
        $messages = [
            'mail_subject' => ['发货提醒：您的订单已交付', '出貨提醒：您的訂單已交付', 'Your order has been delivered', 'Ваш заказ доставлен', 'Đơn hàng của bạn đã được giao'],
            'mail_intro' => ['付款已确认，请妥善保存以下交付内容：', '付款已確認，請妥善保存以下交付內容：', 'Payment confirmed. Keep the following delivery information safe:', 'Оплата подтверждена. Сохраните следующие данные заказа:', 'Thanh toán đã được xác nhận. Vui lòng lưu giữ thông tin giao hàng sau:'],
            'mail_safety' => ['请勿向他人透露卡密。订单号：', '請勿向他人透露卡密。訂單編號：', 'Do not share your codes. Order number:', 'Не передавайте коды другим лицам. Номер заказа:', 'Không chia sẻ mã của bạn với người khác. Mã đơn hàng:'],
            'view_order' => ['查看订单与使用说明', '查看訂單與使用說明', 'View order and instructions', 'Заказ и инструкция', 'Xem đơn hàng và hướng dẫn'],
            'fulfilling' => ['正在发货中，请耐心等待，如有疑问，请联系客服。', '正在出貨中，請耐心等候；如有疑問，請聯絡客服。', 'Your order is being prepared. Please wait or contact support.', 'Ваш заказ обрабатывается. Пожалуйста, подождите или обратитесь в поддержку.', 'Đơn hàng của bạn đang được chuẩn bị. Vui lòng chờ hoặc liên hệ hỗ trợ.'],
            'reviewing' => ['订单正在人工审核中，通过后会立即发货，请耐心等待。', '訂單正在人工審核中，通過後會立即出貨，請耐心等候。', 'Your order is under manual review. Delivery will follow approval.', 'Ваш заказ проходит проверку. Доставка будет выполнена после одобрения.', 'Đơn hàng đang được kiểm tra thủ công. Hàng sẽ được giao sau khi được duyệt.'],
            'sold_out_after_payment' => ['商品暂时缺货，请联系客服处理订单。', '商品暫時缺貨，請聯絡客服處理訂單。', 'This item is temporarily out of stock. Contact support about your order.', 'Товар временно отсутствует. Обратитесь в поддержку по вашему заказу.', 'Sản phẩm tạm thời hết hàng. Vui lòng liên hệ hỗ trợ về đơn hàng của bạn.']
        ];
        $index = array_search(self::normalize($locale), ['zh-cn', 'zh-tw', 'en', 'ru', 'vi'], true);
        return $messages[$key][$index] ?? $key;
    }
}
