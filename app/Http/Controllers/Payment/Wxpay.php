<?php

// Weixin payment operations (Non-official)
// see docs: <https://open.swiftpass.cn/openapi>
// @caoxl

namespace App\Http\Controllers\Payment;

use
    Illuminate\Support\Facades\Validator,
    Illuminate\Http\Request,
    App\Models\Finance\RefundLog;

use App\Traits\{Client, Tool};
use App\Models\User\{User, PaymentLog};

class Wxpay implements \App\Contract\PaymentMethod
{
    use \App\Traits\CURL;

    private $config = null;
    private $amountEscape = 1;

    /**
     * @param array $params
     * @return array|bool
     */
    public function prepare(array &$params)
    {
        if (true !== ($configCheckRes = $this->checkConfig())) {
            return $configCheckRes;
        } elseif (true !== ($paramsValidateRes = $this->validate($params, [
            'client' => 'required|in:wap,mobile',
            'amount' => 'required|numeric|min:0.01',
            'notify' => 'required|url',
            'origin' => 'required',
            'mid'    => 'required|integer|min:1',
            'desc'   => 'required',
        ]))) {
            return $paramsValidateRes;
        }

        if ('wap' == $params['client']) {
            if (true !== ($hasReturnUrl = $this->validate($params, [
                'wxuser_openid' => 'required',
                'return'        => 'required|url',
            ]))) {
                return $hasReturnUrl;
            }

            /*if (!isset($params['wxuser_openid'])) {
                if (false === ($openID = $this->findUserWxOpenID(
                    $params['mid']
                ))) {
                    return [
                        'err' => 5002,
                        'msg' => Tool::sysMsg('MISSING_WXUSER_OPENID'),
                    ];
                } else {
                    $params['wxuser_openid'] = $openID;
                }
            }*/
        }

        return $this->createPaymentLog($params);
    }

    /**
     * @param int $uid
     * @return bool
     */
    protected function findUserWxOpenID(int $uid)
    {
        $user = User::find($uid);

        if (!$user || !isset($user->wx_openid) || !$user->wx_openid) {
            return false;
        }

        return $user->wx_openid;
    }

    /**
     * @return array|bool
     */
    protected function checkConfig()
    {
        $this->config = config('custom')['wxpay_wft'] ?? [];

        if (! $this->config) {
            return [
                'err' => 5001,
                'msg' => Tool::sysMsg('MISSING_WFT_WXPAY_CONFIG'),
            ];
        } elseif (true !== ($configValidateRes = $this->validate($this->config, [
            'gateway'   => 'required|url',
            'jspay_url' => 'required|url',
            'appid_app' => 'required',
            'appid_wap' => 'required',
            'key_app'   => 'required',
            'key_wap'   => 'required',
            'mchid_app' => 'required',
            'mchid_wap' => 'required',
        ]))) {
            return $configValidateRes;
        }

        return true;
    }

    /**
     * @param array $params
     * @return array|bool
     */
    protected function createPaymentLog(array &$params)
    {
        // Generate a trade no and create an payment log record of this user
        $params['trade_no'] = $params['trade_no']
        ?? Tool::tradeNo($params['mid']);

        $params['client_ip'] = Client::ip();

        $data = [
            'uid'      => $params['mid'],
            'from'     => $params['origin'],
            'payment'  => 'wxpay',
            'trade_no' => $params['trade_no'],
            'amount'   => $params['amount'],
            'payed'    => 0,
            'clientip' => $params['client_ip'],
            'dateline' => time(),
        ];

        $_data = [
            '__wx_client' => $params['client']
        ];

        if (isset($params['order_id']) && $params['order_id']) {
            $data['order_id'] = $params['order_id'];
        }

        if (isset($params['data'])
            && is_array($params['data'])
            && $params['data']
        ) {
            $_data = array_merge($_data, $params['data']);
        }

        $data['data'] = json_encode(
            $_data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $paymentLoggedId = PaymentLog::insertGetId($data);

        return $paymentLoggedId ? true : [
            'err' => 5001,
            'msg' => Tool::sysMsg('DATA_UPDATE_ERROR'),
        ];
    }

    /**
     * @param array $params
     * @param array $rules
     * @return array|bool
     */
    protected function validate(array $params, array $rules)
    {
        $validator = Validator::make($params, $rules);

        if ($validator->fails()) {
            return [
                'err' => 400,
                'msg' => $validator->errors()->first(),
            ];
        }

        return true;
    }

    /**
     * @return string
     */
    protected function nonceStr(): string
    {
        return mt_rand(time(), time()+rand());
    }

    /**
     * @param array $params
     * @param string $key
     * @return string
     */
    protected function sign(array $params, string $key): string
    {
        $sign = '';
        ksort($params);
        foreach ($params as $k => $v) {
            if (is_scalar($v) && ('' != $v) && ('sign' != $k)) {
                $sign .= $k.'='.$v.'&';
            }
        }
        $sign .= 'key='.$key;
        $sign  = strtoupper(md5($sign));

        return $sign;
    }

    /**
     * @return RefundLog
     */
    protected function refundLog()
    {
        return new RefundLog;
    }

    /**
     * @param array $params
     * @return array|bool
     */
    public function refund(array $params)
    {
        if (true !== ($configCheckRes = $this->checkConfig())) {
            return $configCheckRes;
        } elseif (true !== ($legalParams = $this->validate($params, [
            'paylog_id' => 'required|integer|min:1',
            'id_type'   => 'required|in:transaction_id,out_trade_no',
            'trade_no'  => 'required',
            'amount'    => 'required|numeric|min:0.01',
            'operator'  => 'required',
        ]))) {
            return $legalParams;
        }

        $createAt = date('Y-m-d H:i:s');

        try {
            $paymentLog = PaymentLog::select('amount', 'data')
                ->whereLogId($params['paylog_id'])
                ->first();

            if (! ($amountTotal = $paymentLog->amount)) {
                return [
                    'err' => 5001,
                    'msg' => Tool::sysMsg('No_PAYMENT_LOG'),
                ];
            }

            if ($paymentLog->data
                && ($extra = json_decode($paymentLog->data, true))
                && isset($extra['__wx_client'])
                && ('wap' == $extra['__wx_client'])
            ) {
                $wxClient = 'wap';
            } else {
                $wxClient = 'app';
            }

            // Check if all trade amount refunded already
            $refundLog = $this->refundLog();
            $refundedAmount = $refundLog->refundedAmount(
                $params['paylog_id'],
                'wxpay'
            );

            if ($refundedAmount->amountRefunded) {
                $amountRefunded = floatval($refundedAmount->amountRefunded);
                $amountTotal    = floatval($amountTotal);
                $amountCanBeRefunded = abs($amountTotal - $amountRefunded);

                if ($amountTotal < $amountCanBeRefunded) {
                    return [
                        'err' => 5002,
                        'msg' => Tool::sysMsg('REFUND_AMOUNT_ILLEGAL'),
                    ];
                } elseif ($amountRefunded >= $amountTotal) {
                    return [
                        'err' => 5003,
                        'msg' => Tool::sysMsg('REFUNDED_ALL_ALREADY'),
                    ];
                }
            }

            $totalFee  = $this->getIntFee($amountTotal);
            $refundFee = $this->getIntFee($params['amount']);

            if ((false === $totalFee) || (false === $refundFee)) {
                return [
                    'err' => 5004,
                    'msg' => Tool::sysMsg('ILLEGAL_FEE_AMOUNT'),
                ];
            }

            $data = [
                'service'    => 'unified.trade.refund',
                'mch_id'     => $this->config['mchid_'.$wxClient],
                'total_fee'  => $totalFee,
                'refund_fee' => $refundFee,
                'op_user_id' => 'mch_wxpay_program',  // static
                'nonce_str'  => $this->nonceStr(),
                'out_refund_no' => Tool::tradeNo(0, '04'),
            ];
            $data[$params['id_type']] = $params['trade_no'];
            $data['sign']             = $this->sign(
                $data,
                $this->config['key_'.$wxClient]
            );

            $xml = Tool::arrayToXML($data);

            $res = $this->requestHTTPApi(
                $this->config['gateway'],
                'POST', [
                    'Content-Type: application/xml; Charset=UTF-8',
                ],
                $xml
            );

            $processAt = date('Y-m-d H:i:s');

            $res['dat'] = Tool::xmlToArray($res['res']);

            unset($res['res']);

            // Check if sign is from swiftpass.cn (No need here anyway)
            // $legalRet = $res['dat']['sign']==$this->sign($res['dat'])

            $errMsg = $res['dat']['err_msg'] ?? false;

            $reason = $params['reason']
            ?? Tool::sysMsg('REFUND_REASON_COMMON');

            $_data = [
                'refund_no'      => $data['out_refund_no'],
                'paylog_id'      => $params['paylog_id'],
                'amount'         => $params['amount'],
                'reason_request' => $reason,
                'operator'       => $params['operator'],
                'create_at'      => $createAt,
                'process_at'     => $processAt,
            ];

            $refundSuccess = false;
            if (isset($res['dat']['status'])
                && (0 == $res['dat']['status'])
                && isset($res['dat']['result_code'])
                && (0 == $res['dat']['result_code'])
                && isset($res['dat']['refund_id'])
            ) {
                // Insert or update into refund log
                $_data['status'] = 1;
                $_data['out_refund_no'] = $res['dat']['refund_id'];

                if (! $refundLog->insert($_data)) {
                    return [
                        'err' => '503X',
                        'msg' => Tool::sysMsg('DATA_UPDATE_ERROR'),
                    ];
                }

                $refundSuccess = true;
            }

            if ($refundSuccess) {
                return [
                    'err' => 0,
                    'msg' => 'ok',
                ];
            } elseif ($errMsg) {
                $_data['status'] = 2;
                $_data['reason_fail'] = $errMsg;

                if (! $refundLog->insert($_data)) {
                    return [
                        'err' => '503X',
                        'msg' => Tool::sysMsg('DATA_UPDATE_ERROR'),
                    ];
                }

                return [
                    'err' => 5005,
                    'msg' => $errMsg,
                ];
            } else {
                return [
                    'err' => 5006,
                    'msg' => Tool::sysMsg('REFUND_REQUEST_FAILED'),
                ];
            }

        } catch (\Exception $e) {
            return [
                'err' => '500X',
                'msg' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param float $amount
     * @return array|bool|float
     */
    public function getIntFee(float $amount)
    {
        $amount = explode('.', $amount*100);
        $amount = isset($amount[0]) ? intval($amount[0]) : false;

        return $amount;
    }

    /**
     * @param array $params
     * @return array
     */
    public function pay(array &$params): array
    {
        if (true !== ($prepareRes = $this->prepare($params))) {
            return $prepareRes;
        }

        $this->amountEscape = in_array(
            env('APP_ENVS'), ['local', 'test', 'stage',]
        ) ? 1 : $this->getIntFee($params['amount']);

        if (false === $this->amountEscape) {
            return [
                'err' => 5001,
                'msg' => Tool::sysMsg('ILLEGAL_FEE_AMOUNT'),
            ];
        }

        $_params = [
            'out_trade_no'   => $params['trade_no'],
            'body'           => $params['desc'],
            'total_fee'      => $this->amountEscape,
            'mch_create_ip'  => $params['client_id'],
            'notify_url'     => $params['notify'],
            'nonce_str'      => $this->nonceStr(),
        ];

        $fillPayDataHandler = 'fillPayDataFor'.ucfirst($params['client']);

        if (! method_exists($this, $fillPayDataHandler)) {
            return [
                'err' => 5002,
                'msg' => Tool::sysMsg('MISSING_PAY_METHOD_HANDLER'),
            ];
        }

        return $this->$fillPayDataHandler($_params, $params);
    }

    /**
     * @param array $params
     * @param array $_params
     * @return array
     */
    protected function fillPayDataForWap(array $params, array $_params)
    {
        $params['service']      = 'pay.weixin.jspay';
        $params['sub_openid']   = $_params['wxuser_openid'];
        $params['callback_url'] = $_params['return'];
        $params['mch_id']       = $this->config['mchid_wap'];
        $params['sub_appid']    = $this->config['appid_wap'];
        $params['sign']         = $this->sign($params, $this->config['key_wap']);

        $xml = Tool::arrayToXML($params);

        $res = $this->requestHTTPApi(
            $this->config['gateway'],
            'POST', [
                'Content-Type: application/xml; Charset=UTF-8',
            ],
            $xml
        );

        if (! ($ret = Tool::xmlToArray($res['res']))
            || !isset($ret['token_id'])
            || !($tokenId = $ret['token_id'])
        ) {
            return [
                'err' => 5001,
                'msg' => (
                    $ret['message'] ?? Tool::sysMsg('WXPAY_REQUEST_FAILED')
                ),
            ];
        }

        $res['dat']['url'] = base64_encode(
            $this->config['jspay_url'].'?token_id='.$tokenId
        );

        unset($res['res']);

        return $res;
    }

    /**
     * @param array $params
     * @param array $_params
     * @return array
     */
    protected function fillPayDataForMobile(array $params, array $_params)
    {
        $params['service']   = 'unified.trade.pay';
        $params['mch_id']    = $this->config['mchid_app'];
        $params['sub_appid'] = $this->config['appid_app'];
        $params['sign']      = $this->sign($params, $this->config['key_app']);

        $xml = Tool::arrayToXML($params);

        $res = $this->requestHTTPApi(
            $this->config['gateway'],
            'POST', [
                'Content-Type: application/xml; Charset=UTF-8',
            ],
            $xml
        );

        $res['dat']['params'] = Tool::xmlToArray($res['res']);

        // For IOS SDK use only
        $res['dat']['params']['amount'] = $this->amountEscape;

        unset($res['res']);

        return $res;
    }

    /**
     * @param $transHook
     * @param string $client
     * @return string
     */
    public function payCallback($transHook, $client = 'app'): string
    {
        $params = [];

        if (true === $this->tradeSuccess($params, $client)) {
            // Update payment log
            // Execute wxpay caller's transhook
            // Find out the payment log
            $paymentLog = PaymentLog::select(
                'log_id', 'uid', 'amount', 'clientip'
            )->whereTradeNoAndPayedAndPayment(
                $params['out_trade_no'],
                0,
                'wxpay'
            )->first();

            if (!$paymentLog || !isset($paymentLog->uid)) {
                return 'fail';
            } elseif (!($user = User::find($paymentLog->uid))) {
                return 'fail';
            }

            \DB::beginTransaction();

            $timestamp = time();
            // Execute transaction hook
            $transHookSuccess = $transHook(
                $user,
                $paymentLog->amount,
                'wxpay',
                $paymentLog->clientip,
                $timestamp
            );

            if ($transHookSuccess) {
                // Update payment log
                $updatedPayStatus = PaymentLog::whereLogId(
                    $paymentLog->log_id
                )->update([
                    'payed' => 1,
                    'payedip' => $paymentLog->clientip,
                    'pay_trade_no' => $params['transaction_id'],
                    'payedtime' => $timestamp,
                ]);

                if ($updatedPayStatus >= 0) {
                    \DB::commit();

                    return 'success';
                }
            }

            \DB::rollBack();
        }

        return 'fail';
    }

    // Verify callback is from swiftpass.cn and payment is success
    /**
     * @param array $params
     * @param string $client
     * @return array|bool
     */
    public function tradeSuccess(array &$params = [], $client = 'app')
    {
        $this->config = config('custom')['wxpay_wft'] ?? false;

        if (!in_array($client, ['app', 'wap'])) {
            return [
                'err' => 5001,
                'msg' => Tool::sysMsg('ILLEGAL_CLIENT_TYPE'),
            ];
        }

        if (true !== ($configValidateRes = $this->validate($this->config, [
            'key_'.$client => 'required',
        ]))) {
            return $configValidateRes;
        }

        $params = Tool::xmlToArray(file_get_contents('php://input'));

        if ($params
            && is_array($params)
            && isset($params['sign'])
            && isset($params['result_code'])
            && isset($params['total_fee'])
            && ($params['sign'] == $this->sign(
                $params,
                $this->config['key_'.$client])
            )
            && (0 == $params['status'])
            && (0 == $params['result_code'])
            && (0 < $params['total_fee'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @return float
     */
    protected function getRefundFee()
    {
        return 0.008;
    }
}