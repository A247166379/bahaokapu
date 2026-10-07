<?php
declare(strict_types=1);
namespace App\Util;

use App\Model\Order;
use Illuminate\Database\Capsule\Manager as DB;
use Kernel\Container\Di;
use Kernel\Exception\JSONException;

/** Native USDT attempts: frozen invoice, confirmed-chain proof and one fulfilment transaction. */
final class UsdtPayment
{
    public const TABLE = 'usdt_payment';
    public static function create(string $tradeNo, float $amount, array $raw, string $returnUrl): array
    {
        if (DB::connection()->transactionLevel() < 1) throw new JSONException('USDT 付款指令必须与业务订单一起创建');
        if (!preg_match('/^\d{18}$/D',$tradeNo)) throw new JSONException('订单号无效');
        try { $config=UsdtConfig::forInvoice($raw); }
        catch (\RuntimeException | \InvalidArgumentException $e) { throw new JSONException($e->getMessage()); }
        if ((int)($raw['_route_id'] ?? 0)<=0) throw new JSONException('USDT 路由无效');
        $buyerKey=(string)($raw['_buyer_key'] ?? hash('sha256',Client::getAddress()));
        if (!preg_match('/^[a-f0-9]{64}$/D',$buyerKey)) throw new JSONException('USDT 访客标识无效');
        $cny=sprintf('%.2F',$amount);
        if (bccomp($cny,'0',2)<=0) throw new JSONException('支付金额无效');
        if (DB::table(self::TABLE)->where('trade_no',$tradeNo)->exists()) throw new JSONException('USDT 支付指令已存在，请使用原付款页面');
        $base=UsdtConfig::cnyToUsdtUnits($cny,$config['rate'],(int)$config['payment_usdt_scale']);
        if (bccomp($base,UsdtConfig::amountToUnits($config['min_usdt']),0)<0) throw new JSONException('USDT 支付金额低于最低付款金额');
        $now=time(); $token=bin2hex(random_bytes(32));
        // This per-buyer lock remains held until the host business transaction commits or rolls back.
        DB::table('usdt_buyer_lock')->insertOrIgnore(['buyer_key'=>$buyerKey,'last_seen'=>$now]);
        DB::table('usdt_buyer_lock')->where('buyer_key',$buyerKey)->lockForUpdate()->first();
        $active=DB::table(self::TABLE)->where('buyer_key',$buyerKey)->where('status','pending')->where('settlement_expires_at','>=',$now)->lockForUpdate()->get(['id'])->count();
        if ($active>=(int)$config['max_active_unpaid_orders']) throw new JSONException('未完成的 USDT 订单过多，请先完成或等待原订单过期');
        DB::table('usdt_buyer_lock')->where('buyer_key',$buyerKey)->update(['last_seen'=>$now]);
        $expires=$now+(int)$config['order_ttl_seconds']; $settlement=$expires+(int)$config['settlement_grace_seconds'];
        // Never reassign a paid invoice's amount while its payment window is valid.
        DB::table(self::TABLE)->where('reservation_until','<',$now)->whereNotNull('active_match_key')->update(['active_match_key'=>null]);
        $maximum=(int)$config['unique_amount_max']; $step=10 ** (6-(int)$config['payment_usdt_scale']);
        $start=random_int(1,$maximum);
        for ($i=0;$i<$maximum;$i++) {
            $tail=(($start+$i-1)%$maximum)+1;
            $units=bcadd($base,(string)($tail*$step),0);
            $key=hash('sha256',$config['receive_address'].'|'.$units);
            if (DB::table(self::TABLE)->where('active_match_key',$key)->exists()) continue;
            $row=['trade_no'=>$tradeNo,'pay_id'=>(int)$raw['_route_id'],'buyer_key'=>$buyerKey,'cny_amount'=>$cny,'receive_address'=>$config['receive_address'],
                'amount_units'=>$units,'active_match_key'=>$key,'config_snapshot'=>json_encode($config,JSON_THROW_ON_ERROR),
                'poll_token_hash'=>hash('sha256',$token),'created_at'=>$now,'expires_at'=>$expires,'settlement_expires_at'=>$settlement,
                'reservation_until'=>$settlement+86400,'last_check_at'=>0,'checking_until'=>0,'status'=>'pending'];
            try { DB::table(self::TABLE)->insert($row); }
            catch (\Illuminate\Database\QueryException $e) {
                // Database uniqueness arbitrates concurrent invoices, including another route using this wallet.
                if ((int)($e->errorInfo[1] ?? 0)===1062 && DB::table(self::TABLE)->where('active_match_key',$key)->exists()) continue;
                throw $e;
            }
            return ['trade_no'=>$tradeNo,'currency'=>'USDT','network'=>'TRC20','receive_address'=>$row['receive_address'],
                'usdt_amount'=>UsdtConfig::unitsToAmount($units),'cny_amount'=>$cny,'rate'=>$config['rate'],'poll_token'=>$token,
                'expires_at'=>$expires,'settlement_expires_at'=>$settlement,'return_url'=>$returnUrl,'poll_interval_seconds'=>$config['poll_interval_seconds']];
        }
        throw new JSONException('该收款地址的付款金额暂时分配完毕，请选择其他支付方式');
    }
    public static function authorize(string $tradeNo, string $token): array
    {
        if (!preg_match('/^\d{18}$/D',$tradeNo) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new JSONException('付款凭证无效');
        $row=DB::table(self::TABLE)->where('trade_no',$tradeNo)->first();
        if (!$row || !hash_equals((string)$row->poll_token_hash,hash('sha256',$token))) throw new JSONException('付款凭证无效');
        return (array)$row;
    }
    public static function check(string $tradeNo, string $token, ?string $txid=null): array
    {
        $row=self::authorize($tradeNo,$token);
        if ($txid!==null && $txid!=='' && !preg_match('/^[a-f0-9]{64}$/Di',$txid)) throw new JSONException('交易哈希格式不正确');
        return self::scan($row,$txid);
    }
    public static function scan(array $row, ?string $txid=null): array
    {
        $now=time(); $state=self::state($row,$now);
        if (in_array($state,['paid','timeout'],true)) return ['status'=>$state];
        // Atomic lease shared by browser requests and the worker; network calls hold no business row locks.
        $snapshot=json_decode((string)$row['config_snapshot'],true,512,JSON_THROW_ON_ERROR);
        $interval=max(8,(int)($snapshot['chain_check_min_interval_seconds'] ?? 8));
        $claimed=DB::table(self::TABLE)->where('id',$row['id'])->where('status','pending')->where('last_check_at','<=',$now-$interval)
            ->where('checking_until','<',$now)->update(['last_check_at'=>$now,'checking_until'=>$now+240]);
        if (!$claimed) return ['status'=>$state];
        try {
            $config=UsdtConfig::normalize(json_decode((string)$row['config_snapshot'],true,512,JSON_THROW_ON_ERROR));
            $verifier=new TronUsdtVerifier($config);
            $consumed=DB::table(self::TABLE)->where('receive_address',$row['receive_address'])->where('amount_units',$row['amount_units'])->where('status','paid')->whereNotNull('txid')->pluck('txid')->all();
            $proof=($txid!==null && $txid!=='')
                ? $verifier->verify(strtolower($txid),$row['receive_address'],$row['amount_units'],(int)$row['created_at'],(int)$row['expires_at'],(int)$config['confirm_seconds'],(int)$config['payment_clock_skew_seconds'])
                : $verifier->findIncomingPayment($row['receive_address'],$row['amount_units'],(int)$row['created_at'],(int)$row['expires_at'],$consumed,(int)$config['confirm_seconds'],(int)$config['payment_clock_skew_seconds']);
            if ($proof!==null) {
                self::settle((string)$row['trade_no'],$proof);
                return ['status'=>'paid'];
            }
            return ['status'=>$state];
        } catch (\Throwable $e) {
            // Do not expose API credentials, upstream payloads or transport internals in public responses/logs.
            PayConfig::log('UsdtTrc20','VERIFY','订单 '.$row['trade_no'].' 尚未完成核验 ('.get_class($e).')');
            return ['status'=>$state,'checking_error'=>true];
        } finally {
            DB::table(self::TABLE)->where('id',$row['id'])->update(['checking_until'=>0]);
        }
    }
    public static function state(array $row, ?int $now=null): string
    {
        $now ??= time();
        if ($row['status']==='paid') return 'paid';
        if ($now>(int)$row['settlement_expires_at']) return 'timeout';
        return $now>(int)$row['expires_at'] ? 'settling' : 'pending';
    }
    /** Only the server-side verifier calls this; it is never exposed as a callback endpoint. */
    private static function settle(string $tradeNo, array $proof): void
    {
        DB::transaction(static function () use ($tradeNo,$proof): void {
            $row=DB::table(self::TABLE)->where('trade_no',$tradeNo)->lockForUpdate()->first();
            if (!$row) throw new JSONException('付款指令不存在');
            if ($row->status==='paid') return;
            $config=json_decode((string)$row->config_snapshot,true,512,JSON_THROW_ON_ERROR);
            $skew=(int)$config['payment_clock_skew_seconds']; $now=time();
            if (!preg_match('/^[a-f0-9]{64}$/D',(string)($proof['txid'] ?? ''))
                || ($proof['currency'] ?? '')!=='USDT' || ($proof['token_address'] ?? '')!==$config['usdt_contract']
                || ($proof['to_address'] ?? '')!==$row->receive_address || !hash_equals((string)$row->amount_units,(string)($proof['amount_units'] ?? ''))
                || (int)($proof['block_time'] ?? 0)<(int)$row->created_at-$skew || (int)($proof['block_time'] ?? 0)>(int)$row->expires_at+$skew
                || (int)($proof['block_time'] ?? 0)+(int)$config['confirm_seconds']>$now || $now>(int)$row->settlement_expires_at) {
                throw new JSONException('链上付款证据与订单不符');
            }
            // The unique TXID is reserved in this transaction BEFORE fulfilment; rollback releases it on failure.
            DB::table(self::TABLE)->where('id',$row->id)->update(['txid'=>$proof['txid']]);
            $order=Order::query()->where('trade_no',$tradeNo)->lockForUpdate()->first();
            if (!$order || (int)$order->pay_id!==(int)$row->pay_id || (int)$order->status!==0
                || bccomp(sprintf('%.2F',(float)($order->gateway_amount ?? $order->amount)),(string)$row->cny_amount,2)!==0) {
                throw new JSONException('业务订单与付款指令不符');
            }
            /** @var \App\Service\Order $service */
            $service=Di::inst()->make(\App\Service\Order::class);
            $service->orderSuccess($order);
            DB::table(self::TABLE)->where('id',$row->id)->update(['status'=>'paid','paid_at'=>$now,'block_time'=>$proof['block_time'],
                'from_address'=>$proof['from_address'] ?? '', 'checking_until'=>0]);
        });
    }
}
