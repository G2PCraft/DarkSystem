<?php

#______           _    _____           _                  
#|  _  \         | |  /  ___|         | |                 
#| | | |__ _ _ __| | _\ `--. _   _ ___| |_ ___ _ __ ___   
#| | | / _` | '__| |/ /`--. \ | | / __| __/ _ \ '_ ` _ \  
#| |/ / (_| | |  |   </\__/ / |_| \__ \ ||  __/ | | | | | 
#|___/ \__,_|_|  |_|\_\____/ \__, |___/\__\___|_| |_| |_| 
#                             __/ |                       
#                            |___/

namespace pocketmine\network\rcon;

use pocketmine\command\RemoteConsoleCommandSender;
use pocketmine\event\server\RemoteServerCommandEvent;
use pocketmine\Server;
use pocketmine\utils\Utils;

class RCON{
	
	const PROTOCOL_VERSION = 3;
	
	private $server;
	private $socket;
	private $password;
	private $workers = [];
	private $clientsPerThread;
	
	public function __construct(Server $server, $password, $port = 19132, $interface = "0.0.0.0", $threads = 1, $clientsPerThread = 50){
		$this->server = $server;
		$this->workers = [];
		$this->password = (string) $password;
		if($this->password === ""){
			$this->server->getLogger()->critical("RCON Başlatılamadı, Şifre Boş Olamaz!");
			return;
		}
		$this->threads = (int) max(1, $threads);
		$this->clientsPerThread = (int) max(1, $clientsPerThread);
		$this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if($this->socket === false or !socket_bind($this->socket, $interface, (int) $port) or !socket_listen($this->socket)){
			$this->server->getLogger()->critical("RCON Başlatılamadı: " . socket_strerror(socket_last_error()));
			$this->threads = 0;
			return;
		}
		socket_set_block($this->socket);
		for($n = 0; $n < $this->threads; ++$n){
			$this->workers[$n] = new RCONInstance($this->server->getLogger(), $this->socket, $this->password, $this->clientsPerThread);
		}
		socket_getsockname($this->socket, $addr, $port);
	}
	
	public function stop(){
		for($n = 0; $n < $this->threads; ++$n){
			$this->workers[$n]->close();
			usleep(50000);
			$this->workers[$n]->close();
			$this->workers[$n]->quit();
		}
		@socket_close($this->socket);
		$this->threads = 0;
	}

	public function check(){
		$d = Utils::getRealMemoryUsage();
		$u = Utils::getMemoryUsage(true);
		$usage = round(($u[0] / 1024) / 1024, 2) . "/" . round(($d[0] / 1024) / 1024, 2) . "/" . round(($u[1] / 1024) / 1024, 2) . "/" . round(($u[2] / 1024) / 1024, 2) . " MB @ " . Utils::getThreadCount() . " threads";
		$serverStatus = serialize([
			"online" => count($this->server->getOnlinePlayers()),
			"max" => $this->server->getMaxPlayers(),
			"upload" => round($this->server->getNetwork()->getUpload() / 1024, 2),
			"download" => round($this->server->getNetwork()->getDownload() / 1024, 2),
			"tps" => $this->server->getTicksPerSecond(),
			"load" => $this->server->getTickUsage(),
			"usage" => $usage
		]);
		for($n = 0; $n < $this->threads; ++$n){
			if(!$this->workers[$n]->isTerminated()){
				$this->workers[$n]->serverStatus = $serverStatus;
			}
			if($this->workers[$n]->isTerminated() === true){
				$this->workers[$n] = new RCONInstance($this->socket, $this->password, $this->clientsPerThread);
			}elseif($this->workers[$n]->isWaiting()){
				if($this->workers[$n]->response !== ""){
					$this->server->getLogger()->info($this->workers[$n]->response);
					$this->workers[$n]->synchronized(function(RCONInstance $thread){
						$thread->notify();
					}, $this->workers[$n]);
				}else{
					$response = new RemoteConsoleCommandSender();
					$command = $this->workers[$n]->cmd;
					$this->server->getPluginManager()->callEvent($ev = new RemoteServerCommandEvent($response, $command));
					if(!$ev->isCancelled()){
						$this->server->dispatchCommand($ev->getSender(), $ev->getCommand());
					}
					$this->workers[$n]->response = $response->getMessage();
					$this->workers[$n]->synchronized(function(RCONInstance $thread){
						$thread->notify();
					}, $this->workers[$n]);
				}
			}
		}
	}
}
