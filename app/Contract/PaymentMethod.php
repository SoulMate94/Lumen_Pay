<?php

// Payment methods contract
// @caoxl

namespace App\Contract;

interface PaymentMethod
{
    // Validate payment parameters before pay action
    public function prepare(array &$params);

    // Pay action
    // @params Payment uses parameters
    // @return format:
    // [
    //    'err' => 0,
    //    'msg' => 'ok',
    //    'dat' => [...],  // optional
    // ]
    public function pay(array &$params): array;
}