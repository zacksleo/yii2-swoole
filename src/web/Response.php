<?php

/**
 * Author: lf
 * Blog: https://blog.feehi.com
 * Email: job@feehi.com
 * Created at: 2017-08-19 10:52
 */

namespace feehi\web;

use Yii;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use yii\helpers\Inflector;
use yii\helpers\Url;
use yii\helpers\FileHelper;
use yii\helpers\StringHelper;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\HttpException;
use yii\web\RangeNotSatisfiableHttpException;
use yii\web\ResponseFormatterInterface;

class Response extends \yii\web\Response
{
    private $_statusCode = 200;

    private $_headers;
    public $swooleResponse;
    public $_cookies;

    public function init()
    {
        if ($this->version === null) {
            $swooleRequest = yii::$app->getRequest()->swooleRequest;
            if (isset($swooleRequest->server['server_protocol']) && $swooleRequest->server['server_protocol'] === 'HTTP/1.0') {
                $this->version = '1.0';
            } else {
                $this->version = '1.1';
            }
        }
        if ($this->charset === null) {
            $this->charset = Yii::$app->charset;
        }
        $this->formatters = array_merge($this->defaultFormatters(), $this->formatters);
    }

    protected function sendHeaders()
    {
        /*if (headers_sent()) {
            return;
        }*/
        $statusCode = $this->getStatusCode();
        $this->swooleResponse->status($statusCode);
        if ($this->_headers) {
            $headers = $this->getHeaders();
            foreach ($headers as $name => $values) {
                $name = str_replace(' ', '-', ucwords(str_replace('-', ' ', $name)));
                // set replace for first occurrence of header but false afterwards to allow multiple
                $this->swooleResponse->header($name, end($values));
            }
        }
        $this->sendCookies();
    }

    protected function sendCookies()
    {
        $session = yii::$app->getSession();
        $data = $session->getCookieParams();
        $this->swooleResponse->cookie($session->getName(), $session->getId(), time() + $data['lifetime'], $data['path'], $data['domain'], $data['secure'], $data['httponly']);
        if ($this->_cookies === null) {
            return;
        }
        $request = Yii::$app->getRequest();
        if ($request->enableCookieValidation) {
            if ($request->cookieValidationKey == '') {
                throw new InvalidConfigException(get_class($request) . '::cookieValidationKey must be configured with a secret key.');
            }
            $validationKey = $request->cookieValidationKey;
        }
        foreach ($this->getCookies() as $cookie) {
            $value = $cookie->value;
            if ($cookie->expire != 1 && isset($validationKey)) {
                $value = Yii::$app->getSecurity()->hashData(serialize([$cookie->name, $value]), $validationKey);
            }
            $this->swooleResponse->cookie($cookie->name, $value, $cookie->expire, $cookie->path, $cookie->domain, $cookie->secure, $cookie->httpOnly);
        }
    }

    protected function sendContent()
    {
        if ($this->stream === null) {
            $this->swooleResponse->end($this->content);

            $session = yii::$app->getSession();
            $session->persist();
            return;
        }

        set_time_limit(0); // Reset time limit for big files
        $chunkSize = 8 * 1024 * 1024; // 8MB per chunk

        if (is_array($this->stream)) {
            list($handle, $begin, $end) = $this->stream;
            fseek($handle, $begin);
            while (!feof($handle) && ($pos = ftell($handle)) <= $end) {
                if ($pos + $chunkSize > $end) {
                    $chunkSize = $end - $pos + 1;
                }
                $this->swooleResponse->write(fread($handle, $chunkSize));
                flush(); // Free up memory. Otherwise large files will trigger PHP's memory limit.
            }
            fclose($handle);
            $this->swooleResponse->end(null);
        } else {
            while (!feof($this->stream)) {
                $this->swooleResponse->write(fread($this->stream, $chunkSize));
                flush();
            }
            fclose($this->stream);
            $this->swooleResponse->end(null);
        }
        $session = yii::$app->getSession();
        $session->persist();
    }

    public function redirect($url, $statusCode = 302, $checkAjax = true)
    {
        if (is_array($url) && isset($url[0])) {
            // ensure the route is absolute
            $url[0] = '/' . ltrim($url[0], '/');
        }
        $url = Url::to($url);
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $hostInfo = Yii::$app->getRequest()->getHostInfo();
            if (strpos($hostInfo, 'http://') === 0 || strpos($hostInfo, 'https://') === 0) {
                $url = $hostInfo . $url;
            } else {
                $url = (yii::$app->getRequest()->getIsSecureConnection() ? "https://" : "http://") . $hostInfo . $url;
            }
        }

        if ($checkAjax) {
            if (Yii::$app->getRequest()->getIsAjax()) {
                if (Yii::$app->getRequest()->getHeaders()->get('X-Ie-Redirect-Compatibility') !== null && $statusCode === 302) {
                    // Ajax 302 redirect in IE does not work. Change status code to 200. See https://github.com/yiisoft/yii2/issues/9670
                    $statusCode = 200;
                }
                if (Yii::$app->getRequest()->getIsPjax()) {
                    $this->swooleResponse->header('X-Pjax-Url', $url);
                } else {
                    $this->swooleResponse->header('X-Redirect', $url);
                }
            } else {
                $this->swooleResponse->header('Location', $url);
            }
        } else {
            $this->swooleResponse->header('Location', $url);
        }

        $this->setStatusCode($statusCode);

        return $this;
    }
}
