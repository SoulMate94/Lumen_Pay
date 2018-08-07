<?php

// Tools, static methods only

namespace App\Traits;

class Tool
{
    public static function isPint($num = null, bool $zero = true)
    {
        $zero = $zero ? -1 : 0;
        $_num = $num;

        return (
            is_numeric($num)
            && (($num = intval($num)) == $_num)
            && ($num > $zero)
        );
    }

    public static function dayDiff(string $start, string $end, int $diff)
    {
        if (!$start || !$end) {
            return false;
        }

        $start = self::strtodate($start);
        $end   = self::strtodate($end);

        return ($diff == (
            (new \Datetime($end))->diff(new \DateTime($start))->format('%a')
            ));
    }

    public static function isSameDay(string $day1, string $day2)
    {
        if (!$day1 || !$day2) {
            return false;
        }

        return (self::strtodate($day1) === self::strtodate($day2));
    }

    public static function strtodate($day)
    {
        if (! is_numeric($day)) {
            // Here we only need the `date` part only
            $day = strtotime(date('Y-m-d', strtotime($day)));
        }

        return date('Y-m-d H:i:s', strtotime(date('Y-m-d', $day)));
    }

    // Generate inner system trade number
    // $mid: member id
    // $mtype: 01 => user; 02 => shop; 03 => staff; 04 => refund; ...
    // $domain: 00 => master
    public static function tradeNo(
        $mid = 0,
        $mtype = '01',
        $domain = '00'
    ): string
    {
        $domain  = str_pad(($domain%42), 2, '0', STR_PAD_LEFT);
        $mid     = str_pad(($mid%1024), 4, '0', STR_PAD_LEFT);
        $mtype   = in_array($mtype, ['01', '02', '03']) ? $mtype : '00';
        $postfix = mb_substr(microtime(), 2, 6);

        return date('YmdHis').$domain.$mtype.$mid.mt_rand(1000, 9999).$postfix;
    }

    public static function sysMsg($key, $lang = 'zh')
    {
        $lang = $_REQUEST['lang'] ?? 'zh';

        if (isset($GLOBALS['__sys_msg'])
            && is_array($GLOBALS['__sys_msg'])
            && $GLOBALS['__sys_msg']
        ) {
            $msg = $GLOBALS['__sys_msg'];
        } else {
            $msg = [];
            $langPath = resource_path().'/sys_msg/';
            $path = $langPath.$lang;
            if (! file_exists($path)) {
                $path = $langPath.'zh';
            }

            if (file_exists($path)) {
                $fsi = new \FilesystemIterator($path);
                foreach ($fsi as $file) {
                    if ($file->isFile() && 'php' == $file->getExtension()) {
                        $_msg = include $file->getPathname();
                        if ($_msg && is_array($_msg)) {
                            $msg = array_merge($_msg, $msg);
                        }
                    }
                }

                $GLOBALS['__sys_msg'] = $msg;
            }
        }

        return $msg[$key]
            ?? (
            ('zh' == $lang)
                ? '服务繁忙，请稍后再试'
                : 'Service is busy or temporarily unavailable.'
            );
    }

    public static function xmlToArray(string $xml)
    {
        return json_decode(json_encode(simplexml_load_string(
            $xml,
            'SimpleXMLElement',
            LIBXML_NOCDATA
        )), true);
    }

    public static function array2XML(array $array, string &$xml): string
    {
        foreach ($array as $key => &$val) {
            if (is_array($val)) {
                $_xml = '';
                $val = self::array2XML($val, $_xml);
            }
            $xml .= "<$key>$val</$key>";
        }

        unset($val);

        return $xml;
    }

    public static function arrayToXML(array $array, $xml = ''): string
    {
        $_xml  = '<?xml version="1.0" encoding="utf-8"?><xml>'
            .self::array2XML($array, $xml)
            .'</xml>';

        return $_xml;

        // Abandoned due to same value collision
        // $xml = new \SimpleXMLElement('<xml/>');
        // array_walk_recursive($array, [$xml, 'addChild']);
        // return preg_replace('/(\n)*/u', '', $xml->asXML());
    }

    public static function isTimestamp($timestamp): bool
    {
        return (
            is_integer($timestamp)
            && ($timestamp >= 0)
            && ($timestamp <= 2147472000)
        );
    }

    public static function jsonResp(
        $data,
        int $status = 200,
        bool $unicode = true
    ) {
        $unicode = $unicode ? JSON_UNESCAPED_UNICODE : null;

        $data = json_encode($data, $unicode);

        return response($data)
            ->header('Content-Type', 'application/json; charset=utf-8');
    }

    public static function rewriteLengthAwarePaginator($items, $total, $perPage, $currentPage = null, array $options = [])
    {
        $_paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            $options
        );

        return $_paginator;
    }
}
