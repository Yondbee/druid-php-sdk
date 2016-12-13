<?php
namespace Genetsis\core\Config\Beans\Cache;

class Memcached {

    /** @var string $host */
    private $host;
    /** @var string $port */
    private $port;

    public function __construct($host, $port)
    {
        $this->setHost($host);
        $this->setPort($port);
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @param string $host
     * @return Memcached
     */
    public function setHost($host)
    {
        $this->host = $host;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @param mixed $port
     * @return Memcached
     */
    public function setPort($port)
    {
        $this->port = $port;
        return $this;
    }

}