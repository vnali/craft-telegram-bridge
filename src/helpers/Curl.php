<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\helpers;

use craft\helpers\App;
use yii\web\ServerErrorHttpException;

class Curl
{
    /**
     * Undocumented function
     *
     * @param string $url
     * @param array|null $data
     * @param bool|null $header
     * @return array
     */
    public static function getWithCurl(string $url, ?array $data = [], ?bool $header = false): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, $header);
        if ($data) {
            curl_setopt($ch, CURLOPT_POST, 1);
            try {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            } catch (\Throwable $th) {
                throw new ServerErrorHttpException($th->getMessage());
            }
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $CURL_IP_PROXY = App::parseEnv('$CURL_IP_PROXY');
        $CURL_PORT_PROXY = App::parseEnv('$CURL_PORT_PROXY');
        if ($CURL_IP_PROXY && $CURL_IP_PROXY != '$CURL_IP_PROXY' && $CURL_PORT_PROXY && $CURL_PORT_PROXY != '$CURL_PORT_PROXY') {
            curl_setopt($ch, CURLOPT_PROXY, $CURL_IP_PROXY . ':' . $CURL_PORT_PROXY);
        }
        $result = curl_exec($ch);
        $errors = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return array($result, $errors, $info);
    }
}
