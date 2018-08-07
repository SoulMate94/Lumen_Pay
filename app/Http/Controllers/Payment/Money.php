<?php

// Money pay operations
// @caoxl

namespace App\Http\Controllers\Payment;

use Illuminate\Support\Facades\Validator;
use App\Traits\{Client, Tool};
use App\Models\User\{User, Log, PaymentLog};

class Money implements \App\Contract\PaymentMethod
{
    public function prepare(array &$params)
    {
        $validator = Validator::make($params, [
            'origin'  => 'required',
            'user_id' => 'required|integer|min:1',
            'amount'  => 'required|numeric|min:0.01',
            'desc'    => 'required',
        ]);

        if ($validator->fails()) {
            return [
                'err' => 400,
                'msg' => $validator->errors()->first(),
            ];
        }
    }

    // !!! Money pay must use database transaction
    public function pay(array &$params): array
    {
        if (true !== ($prepareRes = $this->prepare($params))) {
            return $prepareRes;
        }

        $user = User::find($params['user_id']);

        if (!$user || !is_object($user)) {
            return [
                'err' => 5001,
                'msg' => Tool::sysMsg('NO_USER'),
            ];
        } elseif (($balance = floatval($user->money))
            < ($amount = abs(floatval($params['amount'])))
        ) {
            return [
                'err' => 5002,
                'msg' => Tool::sysMsg('INSUFFICIENT_USER_BALANCE'),
            ];
        }

        \DB::beginTransaction();

        // Execute transaction hook
        $transHookSuccess = false;
        $timestamp = time();
        $clientIP  = Client::ip();

        if (isset($params['__transhook'])) {
            if (($transHook = $params['__transhook'])
                && is_callable($transHook)
            ) {
                $transHookSuccess = $transHook($user);
            }
        } else {
            $transHookSuccess = true;
        }

        if ($transHookSuccess) {
            // Decrease user balance
            $user->money = $balance - $amount;
            if ($user->save()) {
                // Log into database
                $userLoggedId = Log::insertGetId([
                    'uid'      => $params['user_id'],
                    'type'     => 'money',
                    'number'   => -$amount,
                    'balance'  => $user->money,
                    'tran_type'=> 'rollout',
                    'mark'     => '转出--余额转猫豆',
                    'intro'    => $params['desc'],
                    'day'      => date('Ymd', time()),
                    'clientip' => $clientIP,
                    'dateline' => $timestamp,
                ]);
                if ($userLoggedId) {
                    $paymentLoggedId = PaymentLog::insertGetId([
                        'uid'  => $params['user_id'],
                        'from' => $params['origin'],
                        'payment'   => 'money',
                        'trade_no'  => Tool::tradeNo($params['user_id']),
                        'amount'    => $amount,
                        'payed'     => 1,
                        'payedip'   => $clientIP,
                        'payedtime' => $timestamp,
                        'clientip'  => $clientIP,
                        'dateline'  => $timestamp,
                    ]);

                    if ($paymentLoggedId) {
                        \DB::commit();

                        return [
                            'err' => 0,
                            'msg' => 'ok',
                            'dat' => [
                                'balance' => $user->money,
                            ],
                        ];
                    }
                }
            }
        }

        \DB::rollBack();

        return [
            'err' => 5003,
            'msg' => Tool::sysMsg('MONEY_PAY_FAILED'),
            'dat' => [],
        ];
    }
}