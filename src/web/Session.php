<?php

namespace feehi\web;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\web\SessionIterator;

class Session extends \yii\web\Session
{

    /* @description $savePath session存储目录，执行swoole的用户必须对目录有读和写的权限 */
    public $savePath = "/tmp/";

    public $flashParam = '__flash';

    private $_started = false;

    public $handler;

    public $timeout = null;

    /**
     * 设置sessionName
     * 配置文件中可设置sessionName，默认为 feehi_session
     * 'components'=>[
     * ...
     * 'session'=>[
     *      'name' => 'PHPSESSIONID',
     * ]
     * ...
     * ]
     * @var $name
     */
    public $name;

    private $_cookieParams = [
        'lifetime' => 1400,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
    ];

    private $_prefix = "feehi_";


    public function init()
    {
        parent::init();
        if ($this->getIsActive()) {
            Yii::warning('Session is already started', __METHOD__);
            $this->updateFlashCounters();
        }
        if ($this->timeout !== null) $this->_cookieParams['lifetime'] = $this->timeout;
    }


    public function getSessionFullName()
    {
        return $this->getSavePath() . $this->_prefix . $this->getId();
    }

    public function persist()
    {
        $this->open();
        $this->writeSession($this->getId(), \yii\helpers\Json::encode($_SESSION));
    }

    public function open()
    {
        if ($this->getIsActive()) {
            return;
        }
        $_SESSION = $this->readSession($this->getId());
        $this->_started = true;
    }

    /**
     * Session read handler.
     * @internal Do not call this method directly.
     * @param string $id session ID
     * @return array the session data
     */
    public function readSession($id)
    {
        if (!is_dir($this->getSavePath())) FileHelper::createDirectory($this->getSavePath());
        if (!is_readable($this->getSavePath())) {
            throw new InvalidConfigException("SESSION saved path {$this->savePath} is not readable");
        }
        if (!is_writable($this->getSavePath())) {
            throw new InvalidConfigException("SESSION saved path {$this->savePath} is not writable");
        }
        $file = $this->getSessionFullName();
        if (file_exists($file) && is_file($file)) {
            $data = file_get_contents($file);
            $data = json_decode($data, true);
        } else {
            $data = [];
        }
        return $data;
    }

    /**
     * Session 写入
     *  默认写入文件，可重写该方法，设置session存储介质
     * @internal Do not call this method directly.
     * @param string $id session ID
     * @param string $data session data
     * @return bool whether session write is successful
     */
    public function writeSession($id, $data)
    {
        file_put_contents($this->getSessionFullName(), $data);
        return true;
    }

    /**
     * swoole每隔设置的毫秒数执行此方法回收session
     */
    public function gcSession($maxLifetime = 60000)
    {
        $handle = opendir($this->getSavePath());
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != ".." && (strpos($file, $this->_prefix) === 0) && is_file($this->getSavePath() . $file)) {
                if (strpos($file, $this->_prefix) !== 0) continue;
                $lastUpdatedAt = filemtime($this->getSavePath() . $file);
                if (time() - $lastUpdatedAt > $this->getCookieParams()['lifetime']) {
                    unlink($this->getSavePath() . $file);
                }
            }
        }
    }

    public function getCookieParams()
    {
        return $this->_cookieParams;
    }

    public function setCookieParams(array $config)
    {
        $this->_cookieParams = $config;
    }

    public function destroy()
    {
        $this->open();
        if ($this->getIsActive()) {
            $_SESSION = [];
        }
    }

    public function getIsActive()
    {
        return $this->_started;
    }

    private $_hasSessionId;

    public function getHasSessionId()
    {
        if ($this->_hasSessionId === null) {
            $name = $this->getName();
            $request = Yii::$app->getRequest();
            if (!empty($_COOKIE[$name]) && ini_get('session.use_cookies')) {
                $this->_hasSessionId = true;
            } elseif (!ini_get('session.use_only_cookies') && ini_get('session.use_trans_sid')) {
                $this->_hasSessionId = $request->get($name) != '';
            } else {
                $this->_hasSessionId = false;
            }
        }
        return $this->_hasSessionId;
    }

    public function getId()
    {
        if (isset($_COOKIE[$this->getName()])) {
            $id = $_COOKIE[$this->getName()];
        } else {
            $id = uniqid();
        }
        return $id;
    }

    public function regenerateID($deleteOldSession = false)
    {
    }

    public function getName()
    {
        if ($this->name == null) {
            $this->name = 'feehi_session';
        }
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getSavePath()
    {
        if (substr($this->savePath, -1) != '/') {
            $this->savePath .= '/';
        }
        return $this->savePath;
    }

    public function setSavePath($value)
    {
        $this->savePath = $value;
    }

    public function getTimeout()
    {
        if ($this->timeout == null) {
            $this->timeout = 1400;
        }
        return $this->timeout;
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }
}