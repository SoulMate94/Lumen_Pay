<?php

// Alipay operations
// @caoxl

namespace App\Http\Controllers\Payment;

use
    Illuminate\Support\Facades\Validator,
    Illuminate\Http\Request,
    App\Traits\Tool,
    Payment\Common\PayException,
    Payment\Client\Refund,
    Payment\Config,
    App\Models\Finance\RefundLog;

class Alipay implements \App\Contract\PaymentMethod
{
    /**
     * @param array $params
     * @return array|bool
     */
    public function prepare(array &$params)
    {
        $validator = Validator::make($params, [
            'client'   => 'required|in:web,wap,mobile',
            'amount'   => 'require|numeric|min:0.01',
            'notify'   => 'required|url',
            'trade_no' => 'required',
            'desc'     => 'required',
        ]);

        if ($validator->fails()) {
            return [
                'err' => 400,
                'msg' => $validator->errors()->first(),
            ];
        }

        return true;
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

        $amountEscape = in_array(env('APP_ENV'), ['local', 'test', 'stage'])
            ? 0.01
            : $params ['amount'];

        $alipay = app('alipay.' . $params['client']);
        $alipay->setOutTradeNo($params['trade_no']);
        $alipay->setTotalFee($amountEscape);
        $alipay->setSubject($params['desc']);
        $alipay->setNotifyUrl($params['notify']);

        if (isset($params['body'])
            && is_string($params['body'])
            && $params['body']
        ) {
            $alipay->setBody($params['body']);
        }

        $fillPayDatHandler = 'fillPayDataFor' . ucfirst($params['client']);

        if (! method_exists($this, $fillPayDatHandler)) {
            return [
                'err' => 5001,
                'msg' => Tool::sysMsg('MISSING_PAY_METHOD_HANDLER'),
            ];
        }

        return $this->$fillPayDatHandler($alipay, $params);
    }

    /**
     * @param $alipay
     * @param $params
     * @return array
     */
    protected function fillPayDataForMobile(&$alipay, $params): array
    {
        return [
            'err' => 0,
            'msg' => 'ok',
            'dat' => [
                'params' => $alipay->getPayPara(),
            ],
        ];
    }

    /**
     * @param $alipay
     * @param $params
     * @return array
     */
    protected function fillPayDataForWeb(&$alipay, $params): array
    {
        $alipay->setAppPay('N');

        $validator = Validator::make($params, [
            'return' => 'required|url',
        ]);

        if ($validator->fails()) {
            return [
                'err' => 400,
                'msg' => $validator->errors()->first(),
            ];
        }

        // Enable QR pay, optional
        // See: <https://doc.open.alipay.com/support/hotProblemDetail.htm?spm=a219a.7386797.0.0.LjEOn6&source=search&id=226728>
        if (isset($params['qrpay'])
            && in_array($params['qrpay'], [0, 1, 2, 3, 4])
            && method_exists($alipay, 'setQrPayMode')
        ) {
            $alipay->setQrPayMode($params['qrpay']);
        }

        $alipay->setReturnUrl($params['return']);

        return [
            'err' => 0,
            'msg' => 'ok',
            'dat' => [
                'url' => base64_encode($alipay->getPayLink()),
            ],
        ];
    }

    /**
     * @param $alipay
     * @param $params
     * @return array
     */
    protected function fillPayDataForWap(&$alipay, $params): array
    {
        return $this->fillPayDataForWeb($alipay, $params);
    }

    /**
     * @param $client
     * @return bool
     */
    public function tradeSuccess($client): bool
    {
        if (! in_array($client, ['wap', 'web', 'mobile'])) {
            return false;
        } elseif (! app('alipay.' . $client)->verify()) {
            return false;
        } elseif (! ($tradeStatus = ($_REQUEST['trade_status'] ?? false))
            || !in_array($tradeStatus, [
                'TRADE_SUCCESS',
                'TRADE_FINISHED'
            ])
        ) {
            return false;
        }

        return true;
    }

    public function payCallback($transHook): string
    {
        // TODO
    }

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
     * @param array $params
     * @return array|bool
     */
    public function refund(array $params)
    {
        $createAt = time();
        $config   = config('custom')['alipay'] ?? [];

        if (true !== (
            $legalConfig = $this->validate($config, [
                'app_id'          => 'required',
                'sign_type'       => 'required|in:RSA,RSA2',
                'use_sandbox'     => 'required',
                'ali_public_key'  => 'required',
                'rsa_private_key' => 'required'
            ]))) {
            return $legalConfig;
        } elseif (true !== ($legalParams = $this->validate($params, [
                'paylog_id' => 'required|integer|min:1',
                'id_type'   => 'required|in:trade_no,out_trade_no',
                'trade_no'  => 'required',
                'amount'    => 'required|numeric|min:0.01',
                'operator'  => 'required',
            ]))) {
            return $legalParams;
        }

        try {
            $refundLog = RefundLog::wherePaylogId($params['paylog_id'])->first();

            $refundNo  = $refundLog
            ? $refundLog->refund_no
            : Tool::tradeNo(0, '04');

            $reason = $params['reason'] ?? Tool::sysMsg(
                    'REFUND_REASON_COMMON'
            );

            $data = [
                'refund_fee' => $params['amount'],
                'reason'     => $reason,
                'refund_no'  => $refundNo,
            ];

            $data[$params['id_type']] = $params['trade_no'];

            $_data = [
                'refund_no' => $refundNo,
                'amount'    => $params['amount'],
                'paylog_id' => $params['paylog_id'],
                'operator'  => $params['operator'],
                'reason_request' => $reason,
            ];

            $err = 0;
            $msg = 'ok';

            $ret = Refund::run(Config::ALI_REFUND, $config, $data);

            $processAt = time();

            if (isset($ret['code']) && ('10000' == $ret['code'])) {
                $_data['process_at'] = date('Y-m-d H:i:s');
                $_data['status'] = 1;
            } else {
                $err = $ret['code'] ?? 5001;
                $msg = $reasonFail = $ret['msg'] ?? Tool::sysMsg(
                        'REFUND_REQUEST_FAILED'
                );
            }

            if (true !== ($updateOrInsertRefundLogRes = $this->updateOrInsertRefundLog(
                $refundLog,
                $_data,
                $createAt
            ))) {
                return $updateOrInsertRefundLogRes;
            }

            return [
                'err' => $err,
                'msg' => $msg,
                'dat' => $ret,
            ];

        } catch (PayException $pe) {
            $status = ($refundLog && (1 == $refundLog->status)) ? 1 : 2;
            $_data['status']      = $status;
            $_data['reason_fail'] = $pe->getMessage();
            $_data['process_at']  = date('Y-m-d H:i:s');

            if (true !== ($updateOrInsertRefundLogRes = $this->updateOrInsertRefundLog(
                $refundLog,
                $_data,
                $createAt
            ))) {
                return $updateOrInsertRefundLogRes;
            }

            return [
                'err' => '500X',
                'msg' => $pe->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'err' => '503X',
                'msg' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param $refundLog
     * @param $data
     * @param $createAt
     * @return array|bool
     */
    protected function updateOrInsertRefundLog($refundLog, $data, $createAt)
    {
        if ($refundLog) {
            $processRes = RefundLog::whereId($refundLog->id)
            ->update();
        } else {
            date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Shanghai'));
            $_data['create_at'] = date('Y-m-d H:i:s', $createAt);
            $processRes = RefundLog::insert($data);
        }

        return $processRes ? true : [
            $err = 5002,
            $msg = Tool::sysMsg('DATA_UPDATE_ERROR')
        ];
    }
}