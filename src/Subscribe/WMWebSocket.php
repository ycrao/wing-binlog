<?php namespace Wing\Subscribe;

use Wing\Library\ISubscribe;
use Wing\Net\WsClient;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/8/4
 * Time: 22:58
 *
 * @property WsClient $client
 */
class WMWebSocket implements ISubscribe
{
    private $workers = 1;

    private $client = null;
    private $host;
    private $port;
    private $send_times = 0;
    private $send_failure_times = 0 ;

    public function __construct($config)
    {
        $host    = $config["host"];
        $port    = $config["port"];
        $daemon  = $config["daemon"];
        $workers = $config["workers"];

        $this->workers = $workers;
        $this->host    = $host;
        $this->port    = $port;

        $this->startWebsocketService($host, $port, $daemon, $workers);
        sleep(1);
        $this->tryConnect();
    }


    private function tryConnect()
    {
        $this->client = null;
        try {
            $this->client = new WsClient($this->host, $this->port, '/');
        } catch (\Exception $e) {
            var_dump($e->getMessage());
			$this->client = null;
        }
    }

    private function send($msg)
    {
        $msg .= "\r\n\r\n\r\n";
        try {
        	$this->send_times++;

        	if (!$this->client->send($msg)) {
            	$this->send_failure_times++;
                $this->client = null;
                $this->tryConnect();
                $this->send_times++;
                $this->client->send($msg);
            }

            wing_debug("websocket发送次数：", $this->send_times,"  失败次数：", $this->send_failure_times);
        } catch(\Exception $e){
            var_dump($e->getMessage());
        }
    }

    private function startWebsocketService($host, $port, $deamon, $workers)
    {
        $command = "php ".HOME."/services/websocket.php start --host=".$host." --port=".$port." --workers=".$workers;

        if ($deamon) {
            $command .= " -d";
        }

        $command = "/bin/sh -c \"".$command."\" >>".HOME."/logs/websocket.log&";
        wing_debug($command);
        $handle  = popen($command,"r");

        if ($handle) {
            pclose($handle);
        }
    }

    public function onchange($event)
    {
        $this->send(json_encode($event));
    }
}