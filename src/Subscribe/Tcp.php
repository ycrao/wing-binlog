<?php namespace Wing\Subscribe;

use Wing\Library\ISubscribe;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/8/4
 * Time: 22:58
 */
class Tcp implements ISubscribe
{
    private $host;
    private $port;
    private $client;
    private $send_times    = 0;
    private $failure_times = 0;

    public function __construct($config)
    {
        $this->host    = $config["host"];
        $this->port    = $config["port"];
        $this->client  = null;

        $daemon  = $config["daemon"];
        $workers = $config["workers"];
        $this->startTcpService($this->host, $this->port, $daemon, $workers);
        register_shutdown_function(function(){
            wing_debug("退出tcp服务");
            exec(HOME."/services/tcp stop");
        });

    }

    private function startTcpService($host, $port,$deamon, $workers)
    {
    	$command = HOME."/services/tcp ".$port;
        if (WING_DEBUG) {
            $command .= " --debug";
        }
    	wing_debug($command);
        $handle  = popen($command." >>".HOME."/logs/tcp.log&","r");
        if ($handle) {
            pclose($handle);
        }
    }

    private function send($msg)
    {
        $this->send_times++;
        wing_debug("tcp client总发送次数=》", $this->send_times);
        try {

            if (!$this->client) {
                $this->tryCreateClient();
            }
            if (!fwrite($this->client, $msg . "\r\n\r\n\r\n")) {
                $this->client = null;
                $this->failure_times++;
                $this->tryCreateClient();
                fwrite($this->client, $msg . "\r\n\r\n\r\n");
                $this->send_times++;
                wing_debug("tcp client总发送次数=》", $this->send_times);
            }
            wing_debug("tcp client总发送失败次数=》", $this->failure_times);
        }catch (\Exception $e) {
            var_dump($e->getMessage());
            $this->client = null;
        }
    }

    private function tryCreateClient() {
        try {
            $this->client = stream_socket_client("tcp://" . $this->host . ":" . $this->port, $errno, $errstr, 30);
            if (!$this->client) {
                wing_debug("stream_socket_client错误：$errstr ($errno)");
                $this->client = null;
            }
        } catch (\Exception $e) {
            var_dump($e->getMessage());
            $this->client = null;
        }
    }


    public function onchange($event)
    {
        $this->send(json_encode($event));
    }
}