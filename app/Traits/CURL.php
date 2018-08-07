<?php

// Simple CURL Helper funcitons
// @caoxl

namespace App\Traits;

trait CURL
{
    public function requestJsonApi(
        $uri,
        $type = 'POST',
        $params = [],
        $headers = []
    ) {
        $headers = [
            'Content-Type: application/json; Charset=UTF-8',
        ];

        $res = $this->requestHTTPApi($uri, $type, $headers, $params);

        if (! $res['err']) {
            $res['res'] = json_decode($res['res'], true);
        }

        return $res;
    }

    public function requestHTTPApi(
        string $uri,
        string $type = 'GET',
        array $headers = [],
        $data
    ) {
        $setOpt = [
            CURLOPT_URL            => $uri,
            CURLOPT_RETURNTRANSFER => true,
        ];

        if ($headers) {
            $setOpt[CURLOPT_HTTPHEADER] = $headers;
        }

        if ('POST' == $type) {
            $setOpt[CURLOPT_POST]       = true;
            $setOpt[CURLOPT_POSTFIELDS] = $data;
        }

        $ch = curl_init();
        curl_setopt_array($ch, $setOpt);
        $res = curl_exec($ch);

        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);

        curl_close($ch);

        return [
            'err' => $errNo,
            'msg' => ($errMsg ?: 'ok'),
            'res' => $res,
        ];
    }
}
