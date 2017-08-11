<?php namespace Wing\Library;

/**
 * EventWorker.php
 * User: huangxiaoan
 * Created: 2017/8/4 12:26
 * Email: huangxiaoan@xunlei.com
 */
class EventWorker extends BaseWorker
{
	private $all_pos            = [];
	private $dispatch_pipes     = [];
	private $dispatch_processes = [];

	private $write_run_time     = 0;
	private $pdo;

	public function __construct()
	{
        $this->pdo   = new PDO();
		$this->workers = 32;//$workers;
		for ($i = 1; $i <= $this->workers; $i++) {
		    $this->task[$i] = 0;
        }
	}

	private function startParseProcess()
	{
		if (count($this->all_pos) <= 0) {
			return false;
		}
		list($start_pos, $end_pos) = array_shift($this->all_pos);
		$descriptorspec = array(
			0 => array("pipe", "r"),
			1 => array("pipe", "w"),
			2 => array("pipe", "w")
		);
		$cmd = "php " . HOME . "/services/parse_worker --start=".$start_pos." --end=".$end_pos;
		echo "开启parse进程, ", $cmd,"\r\n";
		$this->dispatch_processes[] = proc_open($cmd, $descriptorspec, $pipes);
		$this->dispatch_pipes[]     = $pipes[1];
		//不阻塞
		stream_set_blocking($pipes[1], 0);
		fclose($pipes[0]);
		fclose($pipes[2]); //标准错误直接关闭 不需要
		return true;
	}


	private function waitParseProcess()
	{
		if (count($this->dispatch_pipes) <= 0) {
			return;
		}

		echo "等待dispatch进程返回结果\r\n";

		while (1) {
			$all_count= count($this->dispatch_pipes);
			$read     = $this->dispatch_pipes;
			$write    = null;
			$except   = null;
			$timeleft = 60;

			$ret = stream_select(
				$read,
				$write,// = null,
				$except,// = null,
				$timeleft
			);

			if ($ret === false || $ret === 0) {
				echo "等待出错\r\n";
				foreach ($this->dispatch_pipes as $key => $value) {
					fclose($value);
					unset($this->dispatch_pipes[$key]);
					proc_close($this->dispatch_processes[$key]);
					unset($this->dispatch_processes[$key]);
				}
				return;
			}

			foreach ($read as $sock) {
				$raw = stream_get_contents($sock);
				echo "parse进程返回值===", "\r\n";
				$events = json_decode($raw, true);
				self::$event_times += count($events);
                //var_dump($events);
				echo "总事件次数：", self::$event_times, "\r\n";

				fclose($sock);
				$id = array_search($sock, $this->dispatch_pipes);
				unset($this->dispatch_pipes[$id]);
				proc_close($this->dispatch_processes[$id]);
				unset($this->dispatch_processes[$id]);
				$all_count--;
			}

			if ($all_count <= 0) {
				break;
			}
		}
	}


	private function forkParseWorker()
    {
    	echo "发生事件\r\n";
		$this->write_run_time = time();
    	if (count($this->all_pos) <= 0) {
    		return;
		}
    	if (count($this->dispatch_pipes) < $this->workers) {
    		$count = $this->workers - count($this->dispatch_pipes);
    		//启动 $count 个 dispatch 进程
			for ($i = 0; $i < $count; $i++) {
				$this->startParseProcess();
			}
		}

		$this->waitParseProcess();
    }

	public function start($daemon = false)
	{
		$process_id = pcntl_fork();

		if ($process_id < 0) {
			echo "fork a process fail\r\n";
			exit;
		}

		if ($process_id > 0) {
			return $process_id;
		}


		if ($daemon) {
		    reset_std();
        }

		$process_name = "wing php >> events collector process";
		self::$process_title = $process_name;

		//设置进程标题 mac 会有warning 直接忽略
		set_process_title($process_name);


		$pdo             = new PDO();
		$bin             = new BinLog($pdo);
		$limit           = 10000;
        $last_binlog     = null;
        $current_binlog  = null;
        $last_start_pos  =
        $last_end_pos    = 0;
        $run_count       = 0;
        $is_run          = intval(1000000/self::USLEEP);

		while (1) {
			ob_start();

			try {

				pcntl_signal_dispatch();
                do {
					if (count($this->all_pos) > 0) {
						echo count($this->all_pos) ,"待处理任务\r\n";
						$this->forkParseWorker();
						break;
					}

//					if (count($this->all_pos) >= $this->workers || (time()- $this->write_run_time) >= 1) {
//						echo count($this->all_pos) ,"待处理任务\r\n";
//						$this->forkParseWorker();
//					}

                    $run_count++;
                    //最后操作的binlog文件
                    if (null == $last_binlog || $run_count % $is_run == 0) {
                        $last_binlog = $bin->getLastBinLog();
                    }

                    if (null == $current_binlog || $run_count % $is_run == 0) {
                        //当前使用的binlog 文件
                        $current_binlog = $bin->getCurrentLogInfo()["File"];
                    }

                    if ($last_start_pos == 0 && $last_end_pos == 0) {
                        //获取最后读取的位置
                        list($last_start_pos, $last_end_pos) = $bin->getLastPosition();
                    }

                    //binlog切换时，比如 .00001 切换为 .00002，重启mysql时会切换
                    //重置读取起始的位置
                    if ($last_binlog != $current_binlog) {
                        $bin->setLastBinLog($current_binlog);
                        $last_start_pos =
                        $last_end_pos = 0;
                        $bin->setLastPosition($last_start_pos, $last_end_pos);
                    }

                    //得到所有的binlog事件
                    $data = $bin->getEvents($current_binlog, $last_end_pos, $limit);
                    if (is_array($data) && count($data) > 0) {
                    	//var_dump($data);
					}

                    if (!$data) {
                        break;
                    }

                    $start_pos   = $data[0]["Pos"];
                    $has_session = false;

                    $all_pos = [];
                    foreach ($data as $row) {
                        if ($row["Event_type"] == "Xid") {
                           // $worker = $this->getWorker("dispatch_process");

                            $all_pos[] = [$start_pos, $row["End_log_pos"]];
                            //if (WING_DEBUG)
                           // echo "写入pos位置：", $start_pos . "-" . $row["End_log_pos"], "\r\n";

//                            if (!$res && WING_DEBUG) {
//                                echo "失败\r\n";
//                            }
//                            if ($run_count % $is_run == 0) {
//                                //设置最后读取的位置
//                                $bin->setLastPosition($start_pos, $row["End_log_pos"]);
//                            }
                            $last_start_pos = $start_pos;
                            $last_end_pos   = $row["End_log_pos"];

                            $has_session    = true;
                            $start_pos      = $row["End_log_pos"];
                        }
                    }

					$bin->setLastPosition($last_start_pos, $last_end_pos);
                    if (count($all_pos) > 0) {
                        $s = $all_pos[0][0];
//                        $t = array_pop($all_pos);
//                        $e = $t[1];
//                        $this->all_pos[] = [$s, $e];

                        $cc  = 0;
                        $len = count($all_pos);
                        foreach ($all_pos as $po) {
                            $cc++;
                            if ($cc >= 100) {
                                $this->all_pos[] = [$s, $po[1]];
                                $s  = $po[1];
                                $cc = 0;
                            }
                        }
                        $this->all_pos[] = [$s, $all_pos[$len-1][1]];
                    }

                        //如果没有查找到一个事务 $limit x 2 直到超过 100000 行
						if (!$has_session) {
							$limit = 2 * $limit;
							if (WING_DEBUG)
							echo "没有找到事务，更新limit=", $limit, "\r\n";
							if ($limit >= 80000) {
								//如果超过8万 仍然没有找到事务的结束点 放弃采集 直接更新游标
								$row = array_pop($data);
								if (WING_DEBUG) {
									echo "查询超过8万，没有找到事务，直接更新游标";
									echo $start_pos, "=>", $row["End_log_pos"], "\r\n";
								}

								$bin->setLastPosition($start_pos, $row["End_log_pos"]);

								$last_start_pos = $start_pos;
                                $last_end_pos   = $row["End_log_pos"];
								$limit          = 10000;
							}
						} else {
							$limit = 10000;
						}
                        if ($run_count%$is_run == 0) {
						    $run_count = 0;
                        }
					} while (0);

			} catch (\Exception $e) {
				if (WING_DEBUG)
				var_dump($e->getMessage());
				unset($e);
			}

			$output = ob_get_contents();

			ob_end_clean();

			if ($output && WING_DEBUG) {
				echo $output,"\r\n";
			}
			unset($output);
			usleep(self::USLEEP);
		}

		return 0;
	}
}