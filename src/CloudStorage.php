<?php

namespace sigalx\selectel;

use GuzzleHttp\Client;

class CloudStorageException extends \Exception
{
}

class CloudStorage
{
    /** @var CloudStorage */
    private static $_instance;

    /**
     * Used in global single-instance-style
     * @var array
     */
    public static $credentials = [];

    /** @var string */
    protected $_authUser;

    /** @var string */
    protected $_authKey;

    /** @var string */
    protected $_containerName;

    /** @var string */
    protected $_attachedDomain;

    /** @var string */
    protected $_authToken;

    /** @var int */
    protected $_authTokenExpiresAt;

    /** @var string */
    protected $_storageUrl;

    /**
     * For global single-instance-style using
     * @return CloudStorage
     */
    public static function instance(): CloudStorage
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new static(static::$credentials);
        }
        return self::$_instance;
    }

    /**
     * @param array $credentials
     * authUser
     * authKey
     */
    public function __construct(array $credentials = [])
    {
        $this->_authUser = $credentials['authUser'];
        $this->_authKey = $credentials['authKey'];
        $this->_containerName = $credentials['containerName'];
        $this->_attachedDomain = $credentials['attachedDomain'] ?? null;
    }

    private function __clone()
    {
    }

    public function __destruct()
    {
    }

    public function clearAuthInfo(): CloudStorage
    {
        $this->_authToken = null;
        $this->_authTokenExpiresAt = null;
        $this->_storageUrl = null;
        return $this;
    }

    /**
     * @return CloudStorage
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws CloudStorageException
     */
    public function renewAuthInfo(): CloudStorage
    {
        $httpClient = new Client();
        $httpResponse = $httpClient->request('GET', 'https://auth.selcdn.ru/', [
            'headers' => [
                'X-Auth-User' => $this->_authUser,
                'X-Auth-Key' => $this->_authKey,
            ],
        ]);
        if ($httpResponse->getStatusCode() != 204) {
            throw new CloudStorageException("Cannot complete authorization in Cloud Storage: {$httpResponse->getStatusCode()} {$httpResponse->getReasonPhrase()}");
        }
        if ($value = $httpResponse->getHeader('X-Auth-Token')) {
            $this->_authToken = $value[0];
        } else {
            throw new CloudStorageException('Missing X-Auth-Token header');
        }
        if ($value = $httpResponse->getHeader('X-Expire-Auth-Token')) {
            if (!is_numeric($value[0])) {
                throw new CloudStorageException('Bad X-Expire-Auth-Token value');
            }
            $this->_authTokenExpiresAt = time() + intval($value[0]);
        } else {
            throw new CloudStorageException('Missing X-Expire-Auth-Token header');
        }
        if ($value = $httpResponse->getHeader('X-Storage-Url')) {
            $this->_storageUrl = $value[0];
        } else {
            throw new CloudStorageException('Missing X-Storage-Url header');
        }
        return $this;
    }

    /**
     * @param string $content
     * @param string $relativePath
     * @param array $requestHeaders
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws CloudStorageException
     */
    public function uploadFile(string $content, string $relativePath, array $requestHeaders = []): string
    {
        if (!$this->_authTokenExpiresAt || $this->_authTokenExpiresAt >= time()) {
            $this->renewAuthInfo();
        }

        $fileSize = strlen($content);
        $contentMd5 = md5($content);

        $containerUrl = $this->_storageUrl . $this->_containerName;
        $putUri = "{$containerUrl}/{$relativePath}";

        $requestHeaders['X-Auth-Token'] = $this->_authToken;
        $requestHeaders['ETag'] = $contentMd5;

        $httpClient = new Client();
        $httpPutResponse = $httpClient->request('PUT', $putUri, [
            'body' => $content,
            'headers' => $requestHeaders,
        ]);
        if (($statusCode = $httpPutResponse->getStatusCode()) != 201) {
            throw new CloudStorageException("Unexpected status code {$statusCode}");
        }

        if ($this->_attachedDomain) {
            $putUri = str_replace($containerUrl, $this->_attachedDomain, $putUri);
        }

        $httpHeadResponse = $httpClient->request('HEAD', $putUri);
        if (!($statusCode = $httpHeadResponse->getStatusCode()) || $statusCode < 200 || $statusCode > 299) {
            throw new CloudStorageException("Unexpected status code {$statusCode}");
        }
        if (($value = $httpHeadResponse->getHeader('ETag')) && $value[0] != $contentMd5) {
            throw new CloudStorageException('Inappropriate ETag header value');
        }
        if (($value = $httpHeadResponse->getHeader('Content-Length')) && $value[0] != $fileSize) {
            throw new CloudStorageException('Inappropriate Content-Length header value');
        }

        return $putUri;
    }

}
