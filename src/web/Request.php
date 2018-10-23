<?php

/**
 * Author: lf
 * Blog: https://blog.feehi.com
 * Email: job@feehi.com
 * Created at: 2017-08-19 11:03
 */

namespace feehi\web;

use yii;
use yii\base\InvalidConfigException;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\NotFoundHttpException;
use yii\web\RequestParserInterface;

class Request extends \yii\web\Request
{

    /* @var $swooleRequest \swoole_http_request */
    public $swooleRequest;

    private $_rawBody;

    /**
     * @inheritdoc
     */
    public function getRawBody()
    {
        if ($this->_rawBody === null) {
            $this->_rawBody = $this->swooleRequest->rawContent();
        }
        return $this->_rawBody;
    }
}
