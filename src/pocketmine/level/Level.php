<?php

#______           _    _____           _                  
#|  _  \         | |  /  ___|         | |                 
#| | | |__ _ _ __| | _\ `--. _   _ ___| |_ ___ _ __ ___   
#| | | / _` | '__| |/ /`--. \ | | / __| __/ _ \ '_ ` _ \  
#| |/ / (_| | |  |   </\__/ / |_| \__ \ ||  __/ | | | | | 
#|___/ \__,_|_|  |_|\_\____/ \__, |___/\__\___|_| |_| |_| 
#                             __/ |                       
#                            |___/

namespace pocketmine\level;

use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\block\Cactus;
use pocketmine\block\Farmland;
use pocketmine\block\Grass;
use pocketmine\block\Ice;
use pocketmine\block\Leaves;
use pocketmine\block\Mycelium;
use pocketmine\block\Sapling;
use pocketmine\block\SnowLayer;
use pocketmine\block\Sugarcane;
use pocketmine\entity\Arrow;
use pocketmine\entity\Entity;
use pocketmine\entity\XPOrb;
use pocketmine\entity\Lightning;
use pocketmine\entity\FloatingText;
use pocketmine\entity\Item as DroppedItem;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\level\ChunkLoadEvent;
use pocketmine\event\level\ChunkPopulateEvent;
use pocketmine\event\level\ChunkUnloadEvent;
use pocketmine\event\level\LevelSaveEvent;
use pocketmine\event\level\LevelUnloadEvent;
use pocketmine\event\level\SpawnChangeEvent;
use pocketmine\event\LevelTimings;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\inventory\InventoryHolder;
use pocketmine\item\Item;
use pocketmine\level\format\Chunk;
use pocketmine\level\format\FullChunk;
use pocketmine\level\format\generic\BaseLevelProvider;
use pocketmine\level\format\generic\EmptyChunkSection;
use pocketmine\level\format\LevelProvider;
use pocketmine\level\particle\Particle;
use pocketmine\level\sound\Sound;
use pocketmine\level\weather\Weather;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Math;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use darksystem\metadata\BlockMetadataStore;
use darksystem\metadata\Metadatable;
use darksystem\metadata\MetadataValue;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\network\protocol\LevelSoundEventPacket;
use pocketmine\network\protocol\SetTimePacket;
use pocketmine\network\protocol\UpdateBlockPacket;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\tile\Chest;
use pocketmine\tile\Tile;
use pocketmine\utils\Cache;
use pocketmine\utils\LevelException;
use pocketmine\utils\MainLogger;
use pocketmine\utils\ReversePriorityQueue;
use darksystem\ChunkGenerator;
use darksystem\crossplatform\DesktopPlayer;
use pocketmine\level\generator\GenerationTask;
use pocketmine\level\generator\Generator;
use pocketmine\level\generator\GeneratorRegisterTask;
use pocketmine\level\generator\GeneratorUnregisterTask;
use pocketmine\utils\Random;
use darksystem\crossplatform\network\protocol\Play\Server\OpenSignEditorPacket;
use pocketmine\level\generator\LightPopulationTask;
use pocketmine\level\generator\PopulationTask;
use pocketmine\entity\monster\Monster;
use pocketmine\entity\animal\Animal;
use pocketmine\nbt\NBT;

class Level extends TimeValues implements ChunkManager, Metadatable{

    /** @var Generator */
	private $generatorInstance;

    private static $chunkLoaderCounter = 1;

	private static $levelIdCounter = 1;
	
	public static $COMPRESSION_LEVEL = 8;
	
	const BLOCK_UPDATE_NORMAL = 1;
	const BLOCK_UPDATE_RANDOM = 2;
	const BLOCK_UPDATE_SCHEDULED = 3;
	const BLOCK_UPDATE_WEAK = 4;
	const BLOCK_UPDATE_TOUCH = 5;
	const BLOCK_UPDATE_REDSTONE = 6;
	
	const DIMENSION_NORMAL = 0;
	const DIMENSION_NETHER = 1;
	const DIMENSION_END = 2;
	
	const DIFFICULTY_PEACEFUL = 0;
	const DIFFICULTY_EASY = 1;
	const DIFFICULTY_NORMAL = 2;
	const DIFFICULTY_HARD = 3;
	
	/** @var Tile[] */
	protected $tiles = [];

	private $motionToSend = [];
	private $moveToSend = [];

	/** @var Player[] */
	protected $players = [];

	/** @var Entity[] */
	protected $entities = [];

	/** @var Entity[] */
	public $updateEntities = [];
	
	/** @var Tile[] */
	public $updateTiles = [];

	protected $blockCache = [];

	/** @var Server */
	protected $server;

	/** @var int */
	protected $levelId;

	/** @var LevelProvider */
	protected $provider;

	/** @var Player[][] */
	protected $usedChunks = [];

	/** @var FullChunk[]|Chunk[] */
	protected $unloadQueue = [];

	protected $time;
	
	public $stopTime;

	private $folderName;

	/** @var FullChunk[]|Chunk[] */
	private $chunks = [];

	/** @var Block[][] */
	protected $changedBlocks = [];
	protected $changedCount = [];

	/** @var ReversePriorityQueue */
	private $updateQueue;
	private $updateQueueIndex = [];

	/** @var Player[][] */
	private $chunkSendQueue = [];
	private $chunkSendTasks = [];

	private $autoSave = true;

	/** @var BlockMetadataStore */
	private $blockMetadata;

	private $useSections;
	//private $blockOrder;
	
	/** @var Position */
	private $temporalPosition;
	/** @var Vector3 */
	private $temporalVector;

	/** @var \SplFixedArray */
	private $blockStates;
	
	protected $playerHandItemQueue = [];
	
	private $chunkGenerationQueue = [];
	private $chunkGenerationQueueSize = 8;
	
	private $chunkPopulationQueue = [];
	private $chunkPopulationLock = [];
	private $chunkPopulationQueueSize = 2;

	protected $chunkTickRadius = 4;
	protected $chunkTickList = [];
	protected $chunksPerTick = 4;
	protected $clearChunksOnTick = false;
	protected $randomTickBlocks = [
		Block::GRASS => Grass::class,
		Block::SAPLING => Sapling::class,
		Block::LEAVES => Leaves::class,
		//Block::WHEAT_BLOCK => Wheat::class,
		Block::FARMLAND => Farmland::class,
		Block::SNOW_LAYER => SnowLayer::class,
		Block::ICE => Ice::class,
		Block::CACTUS => Cactus::class,
		Block::SUGARCANE_BLOCK => Sugarcane::class,
		//Block::RED_MUSHROOM => RedMushroom::class,
		//Block::BROWN_MUSHROOM => BrownMushroom::class,
		//Block::PUMPKIN_STEM => PumpkinStem::class,
		//Block::MELON_STEM => MelonStem::class,
		//Block::VINE => true,
		Block::MYCELIUM => Mycelium::class,
		//Block::COCOA_BLOCK => true,
		//Block::CARROT_BLOCK => Carrot::class,
		//Block::POTATO_BLOCK => Potato::class,
		//Block::LEAVES2 => Leaves2::class,
		//Block::BEETROOT_BLOCK => Beetroot::class,
	];
	
	public $timings;
		
	private $isFrozen = false;
		 
	protected static $isMemoryLeakHappend = false;
	
	public $chunkGenerator = null;
	
	private $closed = false;
	
	/** @var Weather */
	private $weather;
	
	private $dimension = self::DIMENSION_NORMAL;
	
	protected $yMask;
	protected $maxY;
	
	public static function chunkHash($x, $z){
		return PHP_INT_SIZE === 8 ? (($x & 0xFFFFFFFF) << 32) | ($z & 0xFFFFFFFF) : $x . ":" . $z;
	}

	public static function blockHash($x, $y, $z){
		return PHP_INT_SIZE === 8 ? (($x & 0x7FFFFFF) << 36) | (($y & 0xff) << 28) | ($z & 0x7FFFFFF) : $x . ":" . $y .":". $z;
	}

	public static function getBlockXYZ($hash, &$x, &$y, &$z){
		if(PHP_INT_SIZE === 8){
			$x = ($hash >> 36) & 0x7FFFFFF;
			$y = (($hash >> 28) & 0xff);
			$z = ($hash & 0x7FFFFFF);
		}else{
			$hash = explode(":", $hash);
			$x = (int) $hash[0];
			$y = (int) $hash[1];
			$z = (int) $hash[2];
		}
	}

	public static function getXZ($hash, &$x, &$z){
		if(PHP_INT_SIZE === 8){
			$x = ($hash >> 32) << 32 >> 32;
			$z = ($hash & 0xFFFFFFFF) << 32 >> 32;
		}else{
			$hash = explode(":", $hash);
			$x = (int) $hash[0];
			$z = (int) $hash[1];
		}
	}
	
    public static function generateChunkLoaderId(ChunkLoader $loader){
        if($loader->getLoaderId() === 0 or $loader->getLoaderId() === null or $loader->getLoaderId() === null){
            return Level::$chunkLoaderCounter++;
        }else{
            throw new \InvalidStateException("ChunkLoader has a loader id already assigned: " . $loader->getLoaderId());
        }
    }
	
	/**
	 * @param Server $server
	 * @param string $name
	 * @param string $path
	 * @param string $provider Class that extends LevelProvider
	 *
	 * @throws \Exception
	 */
	public function __construct(Server $server, $name, $path, $provider){
		$this->blockStates = Block::$fullList;
		$this->levelId = static::$levelIdCounter++;
		$this->blockMetadata = new BlockMetadataStore($this);
		$this->server = $server;
		$this->autoSave = $server->getAutoSave();
		
		if(is_subclass_of($provider, LevelProvider::class, true)){
			$this->provider = new $provider($this, $path);
			$this->yMask = $provider::getYMask();
			$this->maxY = $provider::getMaxY();
		}else{
			throw new LevelException("Provider is not a subclass of LevelProvider");
		}
		
		//$this->server->getLogger()->info($this->server->getLanguage()->translateString("pocketmine.level.preparing", [$this->provider->getName()]));
		
		$this->useSections = $provider::usesChunkSection();
		//$this->blockOrder = $provider::getProviderOrder();
		
		$this->folderName = $name;
		$this->updateQueue = new ReversePriorityQueue();
		$this->updateQueue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
		$this->time = (int) $this->provider->getTime();

		$this->chunkTickRadius = min($this->server->getViewDistance(), max(1, (int) $this->server->getProperty("chunk-ticking.tick-radius", 4)));
		$this->chunksPerTick = (int) $this->server->getProperty("chunk-ticking.per-tick", 0);
		$this->chunkTickList = [];
		$this->clearChunksOnTick = (bool) $this->server->getProperty("chunk-ticking.clear-tick-list", false);

		$this->timings = new LevelTimings($this);
		$this->temporalPosition = new Position(0, 0, 0, $this);
		$this->temporalVector = new Vector3(0, 0, 0);
		$this->chunkGenerator = new ChunkGenerator($this->server->getLoader());
		$this->generator = Generator::getGenerator($this->provider->getGenerator());
		$this->weather = new Weather($this, 0);
		
		$this->initWeather();
		
		$this->setDimension(Level::DIMENSION_NORMAL);
		//TODO: Add nether & end
	}
	
	public function setDimension($dimension){
		$this->dimension = $dimension;
	}

	public function getDimension(){
		return $this->dimension;
	}
	
	public function getWeather(){
		return $this->weather;
	}
	
	public function initWeather(){
		if($this->server->weatherEnabled){
			$this->weather->setCanCalculate(true);
		}else{
			$this->weather->setCanCalculate(false);
		}
	}
	
	public function initLevel(){
		$generator = $this->generator;
		$this->generatorInstance = new $generator($this->provider->getGeneratorOptions());
		$this->generatorInstance->init($this, new Random($this->getSeed()));
		$this->registerGenerator();
	}

	/**
	 * @return BlockMetadataStore
	 */
	public function getBlockMetadata(){
		return $this->blockMetadata;
	}

	/**
	 * @return Server
	 */
	public function getServer(){
		return $this->server;
	}

	/**
	 * @return LevelProvider
	 */
	final public function getProvider(){
		return $this->provider;
	}
	
	/**
	 * @return int
	 */
	final public function getId(){
		return $this->levelId;
	}

	public function close(){
		if($this->closed){
			return false;
		}
		
		if($this->getAutoSave()){
			$this->save();
		}

		foreach($this->chunks as $chunk){
			$this->unloadChunk($chunk->getX(), $chunk->getZ(), false);
		}
		
		$this->unregisterGenerator();

		$this->closed = true;
		$this->chunkGenerator->quit();
		$this->provider->close();
		$this->provider = null;
		$this->blockMetadata = null;
		$this->blockCache = [];
		$this->temporalPosition = null;
		$this->chunkSendQueue = [];
		$this->chunkSendTasks = [];
	}

	public function addSound(Sound $sound, array $players = null){
		$pk = $sound->encode();

		if($players === null){
			$players = $this->getUsingChunk($sound->x >> 4, $sound->z >> 4);
		}

		if($pk !== null){
			if(!is_array($pk)){
				Server::broadcastPacket($players, $pk);
			}else{
				foreach($pk as $p){
					Server::broadcastPacket($players, $p);
				}
			}
		}
	}

	public function addParticle(Particle $particle, array $players = null){
		$pk = $particle->encode();

		if($players === null){
			$players = $this->getUsingChunk($particle->x >> 4, $particle->z >> 4);
		}

		if($pk !== null){
			if(!is_array($pk)){
				Server::broadcastPacket($players, $pk);
			}else{
				foreach($pk as $p){
					Server::broadcastPacket($players, $p);
				}
			}
		}
	}

	/**
	 * @return bool
	 */
	public function getAutoSave(){
		return $this->autoSave === true;
	}

	/**
	 * @param bool $value
	 */
	public function setAutoSave($value){
		$this->autoSave = $value;
	}

	/**
	 * @param bool $force
	 *
	 * @return bool
	 */
	public function unload($force = false){
		$ev = new LevelUnloadEvent($this);

		if($this === $this->server->getDefaultLevel() && $force !== true){
			$ev->setCancelled(true);
		}

		$this->server->getPluginManager()->callEvent($ev);

		if(!$force && $ev->isCancelled()){
			return false;
		}

		//$this->server->getLogger()->info($this->server->getLanguage()->translateString("pocketmine.level.unloading", [$this->getName()]));
		$defaultLevel = $this->server->getDefaultLevel();
		foreach($this->getPlayers() as $p){
			if($this === $defaultLevel || $defaultLevel === null){
				$p->close("Forced default level unload");
			}elseif($defaultLevel instanceof Level){
				$p->teleport($this->server->getDefaultLevel()->getSafeSpawn());
			}
		}

		if($this === $defaultLevel){
			$this->server->setDefaultLevel(null);
		}

		$this->close();
		
		return true;
	}
	
	/**
	 * @param int $chunkX
	 * @param int $chunkZ
	 *
	 * @return Player[]
	 */
	public function getChunkPlayers($chunkX, $chunkZ){
		return isset($this->playerLoaders[$index = Level::chunkHash($chunkX, $chunkZ)]) ? $this->playerLoaders[$index] : [];
	}
	
	/**
	 * @param int $X
	 * @param int $Z
	 *
	 * @return Player[]
	 */
	public function getUsingChunk($X, $Z){
		return isset($this->usedChunks[$index = Level::chunkHash($X, $Z)]) ? $this->usedChunks[$index] : [];
	}

	/**
	 * @param int    $X
	 * @param int    $Z
	 * @param Player $player
	 */
	public function useChunk($X, $Z, Player $player){
		$this->loadChunk($X, $Z);
		$this->usedChunks[Level::chunkHash($X, $Z)][$player->getId()] = $player;
	}

	/**
	 * @param int    $X
	 * @param int    $Z
	 * @param Player $player
	 */
	public function freeChunk($X, $Z, Player $player){
		unset($this->usedChunks[Level::chunkHash($X, $Z)][$player->getId()]);
		$this->unloadChunkRequest($X, $Z, true);
	}
	
	public function checkTime(){
		if($this->stopTime == true){
			return;
		}else{
			$this->time += 1.25;
		}
	}
	
	public function sendTime(){
		$pk = new SetTimePacket();
		$pk->time = (int) $this->time;
		$pk->started = $this->stopTime === false;
		Server::broadcastPacket($this->players, $pk);
	}

	/**
	 * @param int $currentTick
	 *
	 * @return bool
	 */
	public function doTick($currentTick){
		$this->checkTime();
		if(($currentTick % 200) === 0 && $this->server->getConfigBoolean("time-update", true)){
			$this->sendTime();
		}
		if($this->server->weatherEnabled){
			$this->weather->calcWeather($currentTick);
		}
		$this->unloadChunks();
		$X = null;
		$Z = null;
		while($this->updateQueue->count() > 0 && $this->updateQueue->current()["priority"] <= $currentTick){
			$block = $this->getBlock($this->updateQueue->extract()["data"]);
			unset($this->updateQueueIndex[Level::blockHash($block->x, $block->y, $block->z)]);
			$block->onUpdate(Level::BLOCK_UPDATE_SCHEDULED);
		}
		foreach($this->updateEntities as $id => $entity){
			if($entity->closed || !$entity->onUpdate($currentTick)){
				unset($this->updateEntities[$id]);
			}
		}
		if(count($this->updateTiles) > 0){
			foreach($this->updateTiles as $id => $tile){
				if($tile->onUpdate() !== true){
					unset($this->updateTiles[$id]);
				}
			}
		}
		$this->tickChunks();
		if(count($this->changedCount) > 0){
			if(count($this->players) > 0){
				foreach($this->changedCount as $index => $mini){
					for($Y = 0; $Y < 8; ++$Y){
						if(($mini & (1 << $Y)) === 0){
							continue;
						}
						if(count($this->changedBlocks[$index][$Y]) < 256){
							continue;
						}else{
							Level::getXZ($index, $X, $Z);
							foreach($this->getUsingChunk($X, $Z) as $p){
								$p->unloadChunk($X, $Z);
							}
							unset($this->changedBlocks[$index][$Y]);
						}
					}
				}
				$this->changedCount = [];
				if(count($this->changedBlocks) > 0){
					foreach($this->changedBlocks as $index => $mini){
						foreach($mini as $blocks){
							foreach($blocks as $b){
								foreach($this->getUsingChunk($b->x >> 4, $b->z >> 4) as $player){
									$pk = new UpdateBlockPacket();
									$pk->records[] = [$b->x, $b->z, $b->y, $b->getId(), $b->getDamage(), UpdateBlockPacket::FLAG_ALL];
									$player->dataPacket($pk);
								}
							}
						}
					}
					$this->changedBlocks = [];
				}
			}else{
				$this->changedCount = [];
				$this->changedBlocks = [];
			}
		}
		$this->processChunkRequest();
		$data = [];
		$data['moveData'] = $this->moveToSend;
		$data['motionData'] = $this->motionToSend;
		$this->server->packetMgr->pushMainToThreadPacket(serialize($data));
		$this->moveToSend = [];
		$this->motionToSend = [];
		foreach($this->playerHandItemQueue as $senderId => $playerList){
			foreach($playerList as $recipientId => $data){
				if($data['time'] + 1 < microtime(true)){
					unset($this->playerHandItemQueue[$senderId][$recipientId]);
					if($data['sender']->isSpawned($data['recipient'])){
						$data['sender']->getInventory()->sendHeldItem($data['recipient']);
					}
					if(count($this->playerHandItemQueue[$senderId]) == 0){
						unset($this->playerHandItemQueue[$senderId]);
					}
				}
			}
		}
		while(($data = unserialize($this->chunkGenerator->readThreadToMainPacket()))){
			$this->chunkRequestCallback($data['chunkX'], $data['chunkZ'], $data);
		}
	}

	/**
	 * @param Player[] $target
	 * @param Block[]  $blocks
	 * @param int      $flags
	 */
	public function sendBlocks(array $target, array $blocks, $flags = UpdateBlockPacket::FLAG_ALL){
		foreach($blocks as $b){
			if($b === null){
				continue;
			}
			foreach($target as $player){
				$pk = new UpdateBlockPacket();
				if($b instanceof Block){
					$pk->records[] = [$b->x, $b->z, $b->y, $b->getId(), $b->getDamage(), $flags];
				}else{
					$fullBlock = $this->getFullBlock($b->x, $b->y, $b->z);
					$pk->records[] = [$b->x, $b->z, $b->y, $fullBlock >> 4, $fullBlock & 0xf, $flags];
				}
				$player->dataPacket($pk);
			}
		}
	}

	public function clearCache(){
		$this->blockCache = [];
	}

	private function tickChunks(){
		if($this->chunksPerTick <= 0 || count($this->players) === 0){
			$this->chunkTickList = [];
			return;
		}
		$chunksPerPlayer = min(200, max(1, (int) ((($this->chunksPerTick - count($this->players)) / count($this->players)) + 0.5)));
		$randRange = 3 + $chunksPerPlayer / 30;
		$randRange = $randRange > $this->chunkTickRadius ? $this->chunkTickRadius : $randRange;
		foreach($this->players as $player){
			$x = $player->x >> 4;
			$z = $player->z >> 4;
			$index = Level::chunkHash($x, $z);
			$existingPlayers = max(0, isset($this->chunkTickList[$index]) ? $this->chunkTickList[$index] : 0);
			$this->chunkTickList[$index] = $existingPlayers + 1;
			for($chunk = 0; $chunk < $chunksPerPlayer; ++$chunk){
				$dx = mt_rand(-$randRange, $randRange);
				$dz = mt_rand(-$randRange, $randRange);
				$hash = Level::chunkHash($dx + $x, $dz + $z);
				if(!isset($this->chunkTickList[$hash]) && isset($this->chunks[$hash])){
					$this->chunkTickList[$hash] = -1;
				}
			}
		}
		$blockTest = 0;
		$chunkX = $chunkZ = null;
		foreach($this->chunkTickList as $index => $players){
			Level::getXZ($index, $chunkX, $chunkZ);
			if(!isset($this->chunks[$index]) || ($chunk = $this->getChunk($chunkX, $chunkZ, false)) === null){
				unset($this->chunkTickList[$index]);
				continue;
			}elseif($players <= 0){
				unset($this->chunkTickList[$index]);
			}
			foreach($chunk->getEntities() as $entity){
				$entity->scheduleUpdate();
			}
			if($this->useSections){
				foreach($chunk->getSections() as $section){
					if(!($section instanceof EmptyChunkSection)){
						$Y = $section->getY();
						$k = mt_rand(0, 0x7fffffff);
						for($i = 0; $i < 3; ++$i, $k >>= 10){
							$x = $k & 0x0f;
							$y = ($k >> 8) & 0x0f;
							$z = ($k >> 16) & 0x0f;
							$blockId = $section->getBlockId($x, $y, $z);
							if(isset($this->randomTickBlocks[$blockId])){
								$class = $this->randomTickBlocks[$blockId];
								$block = new $class($section->getBlockData($x, $y, $z));
								$block->x = $chunkX * 16 + $x;
								$block->y = ($Y << 4) + $y;
								$block->z = $chunkZ * 16 + $z;
								$block->level = $this;
								$block->onUpdate(Level::BLOCK_UPDATE_RANDOM);
							}
						}
					}
				}
			}else{
				for($Y = 0; $Y < 8 && ($Y < 3 || $blockTest !== 0); ++$Y){
					$blockTest = 0;
					$k = mt_rand(0, 0x7fffffff);
					for($i = 0; $i < 3; ++$i, $k >>= 10){
						$x = $k & 0x0f;
						$y = ($k >> 8) & 0x0f;
						$z = ($k >> 16) & 0x0f;
						$blockTest |= $blockId = $chunk->getBlockId($x, $y + ($Y << 4), $z);
						if(isset($this->randomTickBlocks[$blockId])){
							$class = $this->randomTickBlocks[$blockId];
							$block = new $class($chunk->getBlockData($x, $y + ($Y << 4), $z));
							$block->x = $chunkX * 16 + $x;
							$block->y = ($Y << 4) + $y;
							$block->z = $chunkZ * 16 + $z;
							$block->level = $this;
							$block->onUpdate(Level::BLOCK_UPDATE_RANDOM);
						}
					}
				}
			}
		}
		if($this->clearChunksOnTick){
			$this->chunkTickList = [];
		}
	}
	
	/**
	 * @param bool $force
	 *
	 * @return bool
	 */
	public function save($force = false){
		if(!$this->getAutoSave() && !$force){
			return false;
		}
		$this->server->getPluginManager()->callEvent(new LevelSaveEvent($this));
		$this->provider->setTime((int) $this->time);
		$this->saveChunks();
		if($this->provider instanceof BaseLevelProvider){
			$this->provider->saveLevelData();
		}
		return true;
	}

	public function saveChunks(){
		foreach($this->chunks as $chunk){
			if($chunk->hasChanged()){
				$this->provider->setChunk($chunk->getX(), $chunk->getZ(), $chunk);
				$this->provider->saveChunk($chunk->getX(), $chunk->getZ());
				$chunk->setChanged(false);
			}
		}
	}

	/**
	 * @param Vector3 $pos
	 */
	public function updateAround(Vector3 $pos){
		$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlock($this->temporalVector->setComponents($pos->x - 1, $pos->y, $pos->z))));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(Level::BLOCK_UPDATE_NORMAL);
		}
		$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlock($this->temporalVector->setComponents($pos->x + 1, $pos->y, $pos->z))));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(Level::BLOCK_UPDATE_NORMAL);
		}
		$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlock($this->temporalVector->setComponents($pos->x, $pos->y - 1, $pos->z))));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(Level::BLOCK_UPDATE_NORMAL);
		}
		$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlock($this->temporalVector->setComponents($pos->x, $pos->y + 1, $pos->z))));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(Level::BLOCK_UPDATE_NORMAL);
		}
		$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlock($this->temporalVector->setComponents($pos->x, $pos->y, $pos->z - 1))));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(Level::BLOCK_UPDATE_NORMAL);
		}
		$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($this->getBlock($this->temporalVector->setComponents($pos->x, $pos->y, $pos->z + 1))));
		if(!$ev->isCancelled()){
			$ev->getBlock()->onUpdate(Level::BLOCK_UPDATE_NORMAL);
		}
	}

	/**
	 * @param Vector3 $pos
	 * @param int     $delay
	 */
	public function scheduleUpdate(Vector3 $pos, $delay){
		if(isset($this->updateQueueIndex[$index = Level::blockHash($pos->x, $pos->y, $pos->z)]) && $this->updateQueueIndex[$index] <= $delay){
			return;
		}
		$this->updateQueueIndex[$index] = $delay;
		$this->updateQueue->insert(new Vector3((int) $pos->x, (int) $pos->y, (int) $pos->z), (int) $delay + $this->server->getTick());
	}

	/**
	 * @param AxisAlignedBB $bb
	 *
	 * @return Block[]
	 */
	public function getCollisionBlocks(AxisAlignedBB $bb){
		$minX = Math::floorFloat($bb->minX);
		$minY = Math::floorFloat($bb->minY);
		$minZ = Math::floorFloat($bb->minZ);
		$maxX = Math::floorFloat($bb->maxX + 1);
		$maxY = Math::floorFloat($bb->maxY + 1);
		$maxZ = Math::floorFloat($bb->maxZ + 1);
		$collides = [];
		$v = $this->temporalVector;
		for($v->z = $minZ; $v->z < $maxZ; ++$v->z){
			for($v->x = $minX; $v->x < $maxX; ++$v->x){
				for($v->y = $minY - 1; $v->y < $maxY; ++$v->y){
					$block = $this->getBlock($v);
					if($block->getId() !== 0 && $block->collidesWithBB($bb)){
						$collides[] = $block;
					}
				}
			}
		}
		return $collides;
	}

	/**
	 * @param Vector3 $pos
	 *
	 * @return bool
	 */
	public function isFullBlock(Vector3 $pos){
		if($pos instanceof Block){
			$bb = $pos->getBoundingBox();
		}else{
			$bb = $this->getBlock($pos)->getBoundingBox();
		}

		return $bb !== null && $bb->getAverageEdgeLength() >= 1;
	}

	/**
	 * @param Entity        $entity
	 * @param AxisAlignedBB $bb
	 * @param boolean       $entities
	 *
	 * @return AxisAlignedBB[]
	 */
	public function getCollisionCubes(Entity $entity, AxisAlignedBB $bb, $entities = true){
		$minX = Math::floorFloat($bb->minX);
		$minY = Math::floorFloat($bb->minY);
		$minZ = Math::floorFloat($bb->minZ);
		$maxX = Math::floorFloat($bb->maxX + 1);
		$maxY = Math::floorFloat($bb->maxY + 1);
		$maxZ = Math::floorFloat($bb->maxZ + 1);
		$collides = [];
		$v = $this->temporalVector;
		for($v->z = $minZ; $v->z < $maxZ; ++$v->z){
			for($v->x = $minX; $v->x < $maxX; ++$v->x){
				for($v->y = $minY - 1; $v->y < $maxY; ++$v->y){
					$block = $this->getBlock($v);
					if($block->getId() !== 0){
						$block->collidesWithBB($bb);
					}
				}
			}
		}
		if($entities){
			foreach($this->getCollidingEntities($bb->grow(0.25, 0.25, 0.25), $entity) as $ent){
				$collides[] = clone $ent->boundingBox;
			}
		}
		return $collides;
	}
	
	public function getFullLight(Vector3 $pos){
		$chunk = $this->getChunk($pos->x >> 4, $pos->z >> 4, false);
		$level = 0;
		if($chunk instanceof FullChunk){
			$level = $chunk->getBlockSkyLight($pos->x & 0x0f, $pos->y & $this->getYMask(), $pos->z & 0x0f);
			if($level < 15){
				$level = max($chunk->getBlockLight($pos->x & 0x0f, $pos->y & $this->getYMask(), $pos->z & 0x0f));
			}
		}

		return $level;
	}

	/**
	 * @param $x
	 * @param $y
	 * @param $z
	 *
	 * @return int bitmap, (id << 4) | data
	 */
	public function getFullBlock($x, $y, $z){
		return $this->getChunk($x >> 4, $z >> 4, false)->getFullBlock($x & 0x0f, $y & $this->getYMask(), $z & 0x0f);
	}

	/**
	 * @param Vector3 $pos
	 * @param boolean $cached
	 *
	 * @return Block
	 */
	public function getBlock(Vector3 $pos, $cached = true){
		$index = Level::blockHash($pos->x, $pos->y, $pos->z);
		if($cached === true && isset($this->blockCache[$index])){
			return $this->blockCache[$index];
		}elseif($pos->y >= 0 && $pos->y < $this->getMaxY() && isset($this->chunks[$chunkIndex = Level::chunkHash ($pos->x >> 4, $pos->z >> 4)])){
			$fullState = $this->chunks[$chunkIndex]->getFullBlock($pos->x & 0x0f, $pos->y & $this->getYMask(), $pos->z & 0x0f);
		}else{
			$fullState = 0;
		}
		
		$block = clone $this->blockStates[$fullState & 0xfff];
		
		$block->x = $pos->x;
		$block->y = $pos->y;
		$block->z = $pos->z;
		$block->level = $this;

		return $this->blockCache[$index] = $block;
	}

	public function updateAllLight(Vector3 $pos){
		$this->updateBlockSkyLight($pos->x, $pos->y, $pos->z);
		$this->updateBlockLight($pos->x, $pos->y, $pos->z);
	}

	public function updateBlockSkyLight($x, $y, $z){
		
	}

	public function updateBlockLight($x, $y, $z){
		$lightPropagationQueue = new \SplQueue();
		$lightRemovalQueue = new \SplQueue();
		$visited = [];
		$removalVisited = [];
		$oldLevel = $this->getChunk($x >> 4,  $z >> 4, true)->getBlockLight($x & 0x0f,  $y & $this->getYMask(),  $z & 0x0f);
		$newLevel = (int) Block::$light[$this->getChunk($x >> 4,  $z >> 4, true)->getBlockId($x & 0x0f,  $y & $this->getYMask(),  $z & 0x0f)];
		if($oldLevel !== $newLevel){
			$this->getChunk($x >> 4,  $z >> 4, true)->setBlockLight($x & 0x0f,  $y & $this->getYMask(),  $z & 0x0f,  $newLevel & 0x0f);
			if($newLevel < $oldLevel){
				$removalVisited[Level::blockHash($x, $y, $z)] = true;
				$lightRemovalQueue->enqueue([new Vector3($x, $y, $z), $oldLevel]);
			}else{
				$visited[Level::blockHash($x, $y, $z)] = true;
				$lightPropagationQueue->enqueue(new Vector3($x, $y, $z));
			}
		}

		while(!$lightRemovalQueue->isEmpty()){
			$val = $lightRemovalQueue->dequeue();
			$node = $val[0];
			$lightLevel = $val[1];
			
			$this->computeRemoveBlockLight($node->x - 1, $node->y, $node->z, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x + 1, $node->y, $node->z, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x, $node->y - 1, $node->z, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x, $node->y + 1, $node->z, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x, $node->y, $node->z - 1, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
			$this->computeRemoveBlockLight($node->x, $node->y, $node->z + 1, $lightLevel, $lightRemovalQueue, $lightPropagationQueue, $removalVisited, $visited);
		}

		while(!$lightPropagationQueue->isEmpty()){
			$node = $lightPropagationQueue->dequeue();
			$lightLevel = $this->getChunk($node->x >> 4,  $node->z >> 4, true)->getBlockLight($node->x & 0x0f,  $node->y & $this->getYMask(),  $node->z & 0x0f) - (int) Block::$lightFilter[$this->getChunk($node->x >> 4,  $node->z >> 4, true)->getBlockId($node->x & 0x0f,  $node->y & $this->getYMask(),  $node->z & 0x0f)];
			if($lightLevel >= 1){
				$this->computeSpreadBlockLight($node->x - 1, $node->y, $node->z, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x + 1, $node->y, $node->z, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x, $node->y - 1, $node->z, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x, $node->y + 1, $node->z, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x, $node->y, $node->z - 1, $lightLevel, $lightPropagationQueue, $visited);
				$this->computeSpreadBlockLight($node->x, $node->y, $node->z + 1, $lightLevel, $lightPropagationQueue, $visited);
			}
		}
	}

	private function computeRemoveBlockLight($x, $y, $z, $currentLight, \SplQueue $queue, \SplQueue $spreadQueue, array &$visited, array &$spreadVisited){
		$current = $this->getChunk($x >> 4,  $z >> 4, true)->getBlockLight($x & 0x0f,  $y & $this->getYMask(),  $z & 0x0f);
		if($current !== 0 && $current < $currentLight){
			$this->getChunk($x >> 4,  $z >> 4, true)->setBlockLight($x & 0x0f,  $y & $this->getYMask(),  $z & 0x0f,  0 & 0x0f);
			if(!isset($visited[$index = Level::blockHash($x, $y, $z)])){
				$visited[$index] = true;
				if($current > 1){
					$queue->enqueue([new Vector3($x, $y, $z), $current]);
				}
			}
		}elseif($current >= $currentLight){
			if(!isset($spreadVisited[$index = Level::blockHash($x, $y, $z)])){
				$spreadVisited[$index] = true;
				$spreadQueue->enqueue(new Vector3($x, $y, $z));
			}
		}
	}

	private function computeSpreadBlockLight($x, $y, $z, $currentLight, \SplQueue $queue, array &$visited){
		$current = $this->getChunk($x >> 4,  $z >> 4, true)->getBlockLight($x & 0x0f,  $y & $this->getYMask(),  $z & 0x0f);
		if($current < $currentLight){
			$this->getChunk($x >> 4,  $z >> 4, true)->setBlockLight($x & 0x0f,  $y & $this->getYMask(),  $z & 0x0f,  $currentLight & 0x0f);
			if(!isset($visited[$index = Level::blockHash($x, $y, $z)])){
				$visited[$index] = true;
				if($currentLight > 1){
					$queue->enqueue(new Vector3($x, $y, $z));
				}
			}
		}
	}
	
	public function chunkCacheClear($x, $z){
		if(advanced_cache === true){
			Cache::remove("world:" . $this->getId() . ":" . Level::chunkHash($x, $z));
		}
	}

	/**
	 * @param Vector3 $pos
	 * @param Block   $block
	 * @param bool    $direct
	 * @param bool    $update
	 *
	 * @return bool
	 */
	public function setBlock(Vector3 $pos, Block $block, $direct = false, $update = true){
		if($pos->y < 0 || $pos->y >= $this->getMaxY()){
			return false;
		}
		
		unset($this->blockCache[$index = Level::blockHash($pos->x, $pos->y, $pos->z)]);

		if($this->getChunk($pos->x >> 4, $pos->z >> 4, true)->setBlock($pos->x & 0x0f, $pos->y & $this->getYMask(), $pos->z & 0x0f, $block->getId(), $block->getDamage())){
			if(!$pos instanceof Position){
				$pos = $this->temporalPosition->setComponents($pos->x, $pos->y, $pos->z);
			}
			
			$block->position($pos);
			$index = Level::chunkHash($pos->x >> 4, $pos->z >> 4);
			
			if(advanced_cache === true){
				Cache::remove("world:" . $this->getId() . ":" . $index);
			}

			if($direct){
				$this->sendBlocks($this->getUsingChunk($block->x >> 4, $block->z >> 4), [$block]);
			}else{
				if(!$pos instanceof Position){
					$pos = $this->temporalPosition->setComponents($pos->x, $pos->y, $pos->z);
				}
				
				$block->position($pos);
				
				if(!isset($this->changedBlocks[$index])){
					$this->changedBlocks[$index] = [];
					$this->changedCount[$index] = 0;
				}
				
				$Y = $pos->y >> 4;
				if(!isset($this->changedBlocks[$index][$Y])){
					$this->changedBlocks[$index][$Y] = [];
					$this->changedCount[$index] |= 1 << $Y;
				}
				
				$this->changedBlocks[$index][$Y][] = clone $block;
			}

			if($update){
				$this->updateAllLight($block);
				$this->server->getPluginManager()->callEvent($ev = new BlockUpdateEvent($block));
				if(!$ev->isCancelled()){
					$ev->getBlock()->onUpdate(Level::BLOCK_UPDATE_NORMAL);
					foreach($this->getNearbyEntities(new AxisAlignedBB($block->x - 1, $block->y - 1, $block->z - 1, $block->x + 2, $block->y + 2, $block->z + 2)) as $entity){
						$entity->scheduleUpdate();
					}
				}

				$this->updateAround($pos);
			}

			return true;
		}

		return false;
	}

	/**
	 * @param Vector3 $source
	 * @param Item    $item
	 * @param Vector3 $motion
	 * @param int     $delay
	 */
	public function dropItem(Vector3 $source, Item $item, Vector3 $motion = null, $delay = 10){
		$motion = $motion === null ? new Vector3(lcg_value() * 0.2 - 0.1, 0.2, lcg_value() * 0.2 - 0.1) : $motion;
		if($item->getId() > 0 && $item->getCount() > 0){
			$chunk = $this->getChunk($source->getX() >> 4, $source->getZ() >> 4);
			if(is_null($chunk)){
				return;
			}
			
			$itemEntity = Entity::createEntity("Item", $this, new CompoundTag("", [
				"Pos" => new ListTag("Pos", [
					new DoubleTag("", $source->getX()),
					new DoubleTag("", $source->getY()),
					new DoubleTag("", $source->getZ())
				]),

				"Motion" => new ListTag("Motion", [
					new DoubleTag("", $motion->x),
					new DoubleTag("", $motion->y),
					new DoubleTag("", $motion->z)
				]),
				"Rotation" => new ListTag("Rotation", [
					new FloatTag("", lcg_value() * 360),
					new FloatTag("", 0)
				]),
				"Health" => new ShortTag("Health", 5),
				"Item" => NBT::putItemHelper($item),
				"PickupDelay" => new ShortTag("PickupDelay", $delay)
			]));
			
			$itemEntity->setScale($itemEntity->getScale() - 0.1);
			$itemEntity->spawnToAll();
		}
	}
	
	/**
	 * @param Vector3 $pos
	 * @param string  $text
	 * @param string  $title
	 *
	 * @return null|Entity
	 */
	public function addFloatingText(Vector3 $pos, $text, $title = ""){
		$entity = Entity::createEntity("FloatingText", $this, new CompoundTag("", [
			new ListTag("Pos", [
				new DoubleTag("", $pos->x),
				new DoubleTag("", $pos->y),
				new DoubleTag("", $pos->z)
			]),
			new ListTag("Motion", [
				new DoubleTag("", 0),
				new DoubleTag("", 0),
				new DoubleTag("", 0)
			]),
			new ListTag("Rotation", [
				new FloatTag("", 0),
				new FloatTag("", 0)
			])
		]));
		
		assert($entity !== null);
		
		if($entity instanceof FloatingText){
			$entity->setTitle($title);
			$entity->setText($text);
		}
		
		$entity->spawnToAll();
		
		return $entity;
	}
	
	/**
	 * @param Vector3 $vector
	 * @param Item    &$item (if null, can break anything)
	 * @param Player  $player
	 *
	 * @return boolean
	 */
	public function useBreakOn(Vector3 $vector, Item &$item = null, Player $player = null){
		$target = $this->getBlock($vector);
		if($item === null){
			$item = Item::get(Item::AIR, 0, 0);
		}
		$drops = $target->getDrops($item); 
		if($player instanceof Player){
			if($player->isAdventure() || $player->isSpectator()){
				return false;
			}
			$ev = new BlockBreakEvent($player, $target, $item, ($player->getGamemode() & 0x01) === 1 ? true : false, $drops);
			if($player->isLiving() && $item instanceof Item && !$target->isBreakable($item)){
				$ev->setCancelled(true);
			}
			if(!$player->isOp() && ($distance = $this->server->getConfigInt("spawn-protection", 16)) > -1){
				$t = new Vector2($target->x, $target->z);
				$s = new Vector2($this->getSpawnLocation()->x, $this->getSpawnLocation()->z);
				if($t->distance($s) <= $distance){
					$ev->setCancelled(true);
				}
			}
			$breakTime = $player->isCreative() ? 0.15 : $target->getBreakTime($item);
			$delta = 0.1;
			if(!$ev->getInstaBreak() && ($player->lastBreak + $breakTime) >= microtime(true) - $delta){
				return false;
			}
			if($player instanceof DesktopPlayer){
				$ev->setInstaBreak(true);
			}
			$this->server->getPluginManager()->callEvent($ev);
			if($ev->isCancelled()){
				return false;
			}
			$drops = $ev->getDrops();
			$player->lastBreak = microtime(true);
			$pos = [ 'x' => $target->x, 'y' => $target->y, 'z' => $target->z ];
			$blockId = $target->getId();
			$player->sendSound(LevelSoundEventPacket::SOUND_BREAK, $pos, 1, $blockId);
			$viewers = $player->getViewers();
			foreach($viewers as $viewer){
				$viewer->sendSound(LevelSoundEventPacket::SOUND_BREAK, $pos, 1, $blockId);
			}
		}elseif($item instanceof Item && !$target->isBreakable($item)){
			return false;
		}
		$level = $target->getLevel();
		if($level instanceof Level){
			$above = $level->getBlock(new Vector3($target->x, $target->y + 1, $target->z));
			if($above instanceof Block){
				if($above->getId() === Item::FIRE){
					$level->setBlock($above, new Air(), true);
				}
			}
		}
		$target->onBreak($item);
		$tile = $this->getTile($target);
		if($tile instanceof Tile){
			if($tile instanceof InventoryHolder){
				if($tile instanceof Chest){
					$tile->unpair();
				}
				foreach($tile->getInventory()->getContents() as $chestItem){
					$this->dropItem($target, $chestItem);
				}
			}
			$tile->close();
		}
		if($item instanceof Item){
			$item->useOn($target);
			if($item->isTool() && $item->getDamage() >= $item->getMaxDurability()){
				$item = Item::get(Item::AIR, 0, 0);
			}
		}
		if(!($player instanceof Player) || $player->isSurvival()){
			foreach($drops as $drop){
				if($drop[2] > 0){
					$this->dropItem($vector->add(0.5, 0.5, 0.5), Item::get(...$drop));
				}
			}
		}
		return true;
	}

	/**
	 * @param Vector3 $vector
	 * @param Item    $item
	 * @param int     $face
	 * @param float   $fx     default 0.0
	 * @param float   $fy     default 0.0
	 * @param float   $fz     default 0.0
	 * @param Player  $player default null
	 *
	 * @return boolean
	 */
	public function useItemOn(Vector3 $vector, Item &$item, $face, $fx = 0.0, $fy = 0.0, $fz = 0.0, Player $player = null){
		$target = $this->getBlock($vector);
		$block = $target->getSide($face);
		if($block->y >= $this->getMaxY() || $block->y < 0){
			return false;
		}
		if($target->getId() === Item::AIR){
			return false;
		}
		if($player instanceof Player){
			$ev = new PlayerInteractEvent($player, $item, $target, $face);
			$this->server->getPluginManager()->callEvent($ev);
			if($player->isSpectator()){
				$ev->setCancelled(true);
			}
			if(!$ev->isCancelled()){
				$target->onUpdate(Level::BLOCK_UPDATE_TOUCH);
				if($target->canBeActivated() && $target->onActivate($item, $player)){
					return true;
				}
				if($item->canBeActivated() && $item->onActivate($this, $player, $block, $target, $face, $fx, $fy, $fz)){
					if($item->getCount() <= 0){
						$item = Item::get(Item::AIR, 0, 0);
						return true;
					}
				}
			}else{
				$player->getInventory()->sendHeldItem($player);
				if($player->getInventory()->getHeldItemSlot() !== -1){
					$player->getInventory()->sendContents($player);
				}
			}
		}elseif($target->canBeActivated() && $target->onActivate($item, $player)){
			return true;
		}
		if($item->isPlaceable()){
			$hand = $item->getBlock();
			$hand->position($block);
		}elseif($block->getId() === Item::FIRE){
			$this->setBlock($block, new Air(), true);
			return false;
		}elseif($block->getId() === Block::SNOW_LAYER){
			$this->setBlock($block, new SnowLayer(), true);
			return false;
		}else{
			return false;
		}
		if(!($block->canBeReplaced() || ($hand->getId() === Item::SLAB && $block->getId() === Item::SLAB))){
			return false;
		}
		if($target->canBeReplaced() === true){
			$block = $target;
			$hand->position($block);
		}
		if($hand->isSolid() && $hand->getBoundingBox() !== null){
			$entities = $this->getCollidingEntities($hand->getBoundingBox());
			$realCount = 0;
			foreach($entities as $e){
				if($e instanceof Arrow || $e instanceof DroppedItem){
					continue;
				}
				if($e instanceof Player && $e->isSpectator()){
					continue;
				}
				if($e === $player){
					if(round($player->getY()) != round($hand->getY()) && round($player->getY() + 1) != round($hand->getY())){
						continue;
					}
				}
				++$realCount;
			}
			if($realCount > 0){
				return false;
			}
		}
		if($player instanceof Player){
			if($player->isAdventure() || $player->isSpectator()){
				return false;
			}
			$ev = new BlockPlaceEvent($player, $hand, $block, $target, $item);
			if(!$player->isOp() && ($distance = $this->server->getConfigInt("spawn-protection", 16)) > -1){
				$t = new Vector2($target->x, $target->z);
				$s = new Vector2($this->getSpawnLocation()->x, $this->getSpawnLocation()->z);
				if($t->distance($s) <= $distance){
					$ev->setCancelled(true);
				}
			}
			if($player instanceof DesktopPlayer){
				if($block instanceof Chest){
					$num_side_chest = 0;
					for($i = 2; $i <= 5; ++$i){
						if(($side_chest = $block->getSide($i))->getId() === $block->getId()){
							++$num_side_chest;
							for($j = 2; $j <= 5; ++$j){
								if($side_chest->getSide($j)->getId() === $side_chest->getId()){
									$ev->setCancelled(true);
								}
							}
						}
					}
					if($num_side_chest > 1){
						$ev->setCancelled(true);
					}
				}
			}
			$this->server->getPluginManager()->callEvent($ev);
			if($ev->isCancelled()){
				return false;
			}
		}
		if(!$hand->place($item, $block, $target, $face, $fx, $fy, $fz, $player)){
			return false;
		}
		if($hand->getId() === Item::SIGN_POST || $hand->getId() === Item::WALL_SIGN){
			$tile = Tile::createTile("Sign", $this, new CompoundTag(false, [
				"id" => new StringTag("id", Tile::SIGN),
				"x" => new IntTag("x", $block->x),
				"y" => new IntTag("y", $block->y),
				"z" => new IntTag("z", $block->z),
				"Text1" => new StringTag("Text1", ""),
				"Text2" => new StringTag("Text2", ""),
				"Text3" => new StringTag("Text3", ""),
				"Text4" => new StringTag("Text4", "")
			]));
			if($player instanceof Player){
				$tile->namedtag->Creator = new StringTag("Creator", $player->getName());
			}elseif($player instanceof DesktopPlayer){
				$pk = new OpenSignEditorPacket();
				$pk->x = $block->x;
				$pk->y = $block->y;
				$pk->z = $block->z;
				$player->putRawPacket($pk);
			}
		}
		$item->setCount($item->getCount() - 1);
		if($item->getCount() <= 0){
			$item = Item::get(Item::AIR, 0, 0);
		}
		return true;
	}

	/**
	 * @param int $entityId
	 *
	 * @return Entity
	 */
	public function getEntity($entityId){
		return isset($this->entities[$entityId]) ? $this->entities[$entityId] : null;
	}

	/**
	 * @return Entity[]
	 */
	public function getEntities(){
		return $this->entities;
	}

	/**
	 * @param AxisAlignedBB $bb
	 * @param Entity        $entity
	 *
	 * @return Entity[]
	 */
	public function getCollidingEntities(AxisAlignedBB $bb, Entity $entity = null){
		$nearby = [];
		if($entity === null || $entity->canCollide){
			$minX = Math::floorFloat(($bb->minX - 2) / 16);
			$maxX = Math::floorFloat(($bb->maxX + 2) / 16);
			$minZ = Math::floorFloat(($bb->minZ - 2) / 16);
			$maxZ = Math::floorFloat(($bb->maxZ + 2) / 16);
			for($x = $minX; $x <= $maxX; ++$x){
				for($z = $minZ; $z <= $maxZ; ++$z){
					foreach((($______chunk = $this->getChunk($x,  $z)) !== null ? $______chunk->getEntities() : []) as $ent){
						if($ent !== $entity && ($entity === null || $entity->canCollideWith($ent)) && $ent->boundingBox->intersectsWith($bb)){
							$nearby[] = $ent;
						}
					}
				}
			}
		}

		return $nearby;
	}

	/**
	 * @param AxisAlignedBB $bb
	 * @param Entity        $entity
	 *
	 * @return Entity[]
	 */
	public function getNearbyEntities(AxisAlignedBB $bb, Entity $entity = null){
		$nearby = [];
		$minX = Math::floorFloat(($bb->minX - 2) / 16);
		$maxX = Math::floorFloat(($bb->maxX + 2) / 16);
		$minZ = Math::floorFloat(($bb->minZ - 2) / 16);
		$maxZ = Math::floorFloat(($bb->maxZ + 2) / 16);
		for($x = $minX; $x <= $maxX; ++$x){
			for($z = $minZ; $z <= $maxZ; ++$z){
				foreach((($______chunk = $this->getChunk($x,  $z)) !== null ? $______chunk->getEntities() : []) as $ent){
					if($ent !== $entity && $ent->boundingBox->intersectsWith($bb)){
						$nearby[] = $ent;
					}
				}
			}
		}

		return $nearby;
	}

	/**
	 * @return Tile[]
	 */
	public function getTiles(){
		return $this->tiles;
	}

	/**
	 * @param $tileId
	 *
	 * @return Tile
	 */
	public function getTileById($tileId){
		return isset($this->tiles[$tileId]) ? $this->tiles[$tileId] : null;
	}

	/**
	 * @return Player[]
	 */
	public function getPlayers(){
		return $this->players;
	}

	/**
	 * @param Vector3 $pos
	 *
	 * @return Tile
	 */
	public function getTile(Vector3 $pos){
		$chunk = $this->getChunk($pos->x >> 4, $pos->z >> 4, false);

		if($chunk !== null){
			return $chunk->getTile($pos->x & 0x0f, $pos->y & 0xff, $pos->z & 0x0f);
		}

		return null;
	}

	/**
	 * @param int $X
	 * @param int $Z
	 *
	 * @return Entity[]
	 */
	public function getChunkEntities($X, $Z){
		return ($chunk = $this->getChunk($X, $Z)) !== null ? $chunk->getEntities() : [];
	}

	/**
	 * @param int $X
	 * @param int $Z
	 *
	 * @return Tile[]
	 */
	public function getChunkTiles($X, $Z){
		return ($chunk = $this->getChunk($X, $Z)) !== null ? $chunk->getTiles() : [];
	}

	/**
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 0-255
	 */
	public function getBlockIdAt($x, $y, $z){
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockId($x & 0x0f, $y & $this->getYMask(), $z & 0x0f);
	}

    /**
     * @param $x
     * @param $y
     * @param $z
     * @param $id
     */
	public function setBlockIdAt($x, $y, $z, $id){
		unset($this->blockCache[Level::blockHash($x, $y, $z)]);
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockId($x & 0x0f, $y & $this->getYMask(), $z & 0x0f, $id & 0xff);
	}

    /**
     * @param $x
     * @param $y
     * @param $z
     * @return int
     */
	public function getBlockDataAt($x, $y, $z){
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockData($x & 0x0f, $y & $this->getYMask(), $z & 0x0f);
	}

    /**
     * @param $x
     * @param $y
     * @param $z
     * @param $data
     */
	public function setBlockDataAt($x, $y, $z, $data){
		unset($this->blockCache[Level::blockHash($x, $y, $z)]);
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockData($x & 0x0f, $y & $this->getYMask(), $z & 0x0f, $data & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 0-15
	 */
	public function getBlockSkyLightAt($x, $y, $z){
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockSkyLight($x & 0x0f, $y & $this->getYMask(), $z & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 * @param int $level 0-15
	 */
	public function setBlockSkyLightAt($x, $y, $z, $level){
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockSkyLight($x & 0x0f, $y & $this->getYMask(), $z & 0x0f, $level & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 *
	 * @return int 0-15
	 */
	public function getBlockLightAt($x, $y, $z){
		return $this->getChunk($x >> 4, $z >> 4, true)->getBlockLight($x & 0x0f, $y & $this->getYMask(), $z & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $y
	 * @param int $z
	 * @param int $level 0-15
	 */
	public function setBlockLightAt($x, $y, $z, $level){
		$this->getChunk($x >> 4, $z >> 4, true)->setBlockLight($x & 0x0f, $y & $this->getYMask(), $z & 0x0f, $level & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $z
	 *
	 * @return int
	 */
	public function getBiomeId($x, $z){
		return $this->getChunk($x >> 4, $z >> 4, true)->getBiomeId($x & 0x0f, $z & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $z
	 *
	 * @return int[]
	 */
	public function getBiomeColor($x, $z){
		return $this->getChunk($x >> 4, $z >> 4, true)->getBiomeColor($x & 0x0f, $z & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $z
	 *
	 * @return int
	 */
	public function getHeightMap($x, $z){
		return $this->getChunk($x >> 4, $z >> 4, true)->getHeightMap($x & 0x0f, $z & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $z
	 * @param int $biomeId
	 */
	public function setBiomeId($x, $z, $biomeId){
		$this->getChunk($x >> 4, $z >> 4, true)->setBiomeId($x & 0x0f, $z & 0x0f, $biomeId);
	}

	/**
	 * @param int $x
	 * @param int $z
	 * @param int $R
	 * @param int $G
	 * @param int $B
	 */
	public function setBiomeColor($x, $z, $R, $G, $B){
		$this->getChunk($x >> 4, $z >> 4, true)->setBiomeColor($x & 0x0f, $z & 0x0f, $R, $G, $B);
	}

	/**
	 * @param int $x
	 * @param int $z
	 * @param int $value
	 */
	public function setHeightMap($x, $z, $value){
		$this->getChunk($x >> 4, $z >> 4, true)->setHeightMap($x & 0x0f, $z & 0x0f, $value);
	}

	/**
	 * @return FullChunk[]|Chunk[]
	 */
	public function getChunks(){
		return $this->chunks;
	}

	/**
	 * @param int  $x
	 * @param int  $z
	 * @param bool $create
	 *
	 * @return FullChunk|Chunk
	 */
	public function getChunk($x, $z, $create = false){
		if(isset($this->chunks[$index = Level::chunkHash($x, $z)])){
			return $this->chunks[$index];
		}elseif($this->loadChunk($x, $z, $create) && $this->chunks[$index] !== null){
			return $this->chunks[$index];
		}

		return null;
	}

	/**
	 * @param int  $x
	 * @param int  $z
	 * @param bool $create
	 *
	 * @return FullChunk|Chunk
	 */
	public function getChunkAt($x, $z, $create = false){
		return $this->getChunk($x, $z, $create);
	}
	
	public function getChunkLoaders($chunkX, $chunkZ){
		return isset($this->chunkLoaders[$index = Level::chunkHash($chunkX, $chunkZ)]) ? $this->chunkLoaders[$index] : [];
	}
	
	public function generateChunkCallback($x, $z, FullChunk $chunk){
		if($this->closed){
			return;
		}
		
		$oldChunk = $this->getChunk($x, $z, false);
		$index = Level::chunkHash($x, $z);
		
		for($xx = -1; $xx <= 1; ++$xx){
			for($zz = -1; $zz <= 1; ++$zz){
				unset($this->chunkPopulationLock[Level::chunkHash($x + $xx, $z + $zz)]);
			}
		}
		unset($this->chunkPopulationQueue[$index]);
		unset($this->chunkGenerationQueue[$index]);
		
		$chunk->setProvider($this->provider);
		$this->setChunk($x, $z, $chunk);
		$chunk = $this->getChunk($x, $z, false);
		if($chunk !== null && ($oldChunk === null || $oldChunk->isPopulated() === false) && $chunk->isPopulated()){
			$this->server->getPluginManager()->callEvent(new ChunkPopulateEvent($chunk));
		}
	}

	public function setChunk($x, $z, FullChunk $chunk, $unload = true){
		$index = Level::chunkHash($x, $z);
		if($unload){
			foreach($this->getUsingChunk($x, $z) as $player){
				$player->unloadChunk($x, $z);
			}
			
			$this->provider->setChunk($x, $z, $chunk);
			$this->chunks[$index] = $chunk;
		}else{
			$this->provider->setChunk($x, $z, $chunk);
			$this->chunks[$index] = $chunk;
		}
		
		if(advanced_cache === true){
			Cache::remove("world:" . $this->getId() . ":" . Level::chunkHash($x, $z));
		}
		
		$chunk->setChanged();
	}

    /**
     * @param $x
     * @param $y
     * @param $z
     * @param Player $player
     */
	public function sendLighting($x, $y, $z, Player $player){
		$pk = new AddEntityPacket();
		$pk->type = Lightning::NETWORK_ID;
		$pk->eid = mt_rand(10000000, 100000000);
		$pk->x = $x;
		$pk->y = $y;
		$pk->z = $z;
		$pk->metadata = array(3, 3, 3, 3);
		$player->dataPacket($pk);
	}

	/**
	 * @param Vector3 $pos
	 * @return Lightning
	 */
	public function spawnLightning(Vector3 $pos){
		$nbt = new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $pos->getX()),
				new DoubleTag("", $pos->getY()),
				new DoubleTag("", $pos->getZ())
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", 0),
				new DoubleTag("", 0),
				new DoubleTag("", 0)
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", 0),
				new FloatTag("", 0)
			]),
		]);

		$lightning = new Lightning($this, $nbt);
		$lightning->spawnToAll();

		return $lightning;
	}
	
	/**
	 * @param Vector3 $pos
	 * @param int $exp
	 * @return bool|XPOrb
	 */
	public function spawnXPOrb(Vector3 $pos, $exp = 1){
		if($exp > 0){
			$nbt = new CompoundTag("", [
				"Pos" => new ListTag("Pos", [
					new DoubleTag("", $pos->getX()),
					new DoubleTag("", $pos->getY() + 0.5),
					new DoubleTag("", $pos->getZ())
				]),
				"Motion" => new ListTag("Motion", [
					new DoubleTag("", 0),
					new DoubleTag("", 0),
					new DoubleTag("", 0)
				]),
				"Rotation" => new ListTag("Rotation", [
					new FloatTag("", 0),
					new FloatTag("", 0)
				]),
				"Experience" => new LongTag("Experience", $exp),
			]);

			$expOrb = new XPOrb($this, $nbt);
			$expOrb->spawnToAll();

			return $expOrb;
		}
		
		return false;
	}
	
	/**
	 * @param int $x
	 * @param int $z
	 *
	 * @return int 0-127
	 */
	public function getHighestBlockAt($x, $z){
		return $this->getChunk($x >> 4, $z >> 4, true)->getHighestBlockAt($x & 0x0f, $z & 0x0f);
	}

	/**
	 * @param int $x
	 * @param int $z
	 *
	 * @return bool
	 */
	public function isChunkLoaded($x, $z){
		return isset($this->chunks[Level::chunkHash($x, $z)]) || $this->provider->isChunkLoaded($x, $z);
	}

	/**
	 * @param int $x
	 * @param int $z
	 *
	 * @return bool
	 */
	public function isChunkGenerated($x, $z){
		$chunk = $this->getChunk($x, $z);
		return $chunk !== null ? $chunk->isGenerated() : false;
	}

	/**
	 * @param int $x
	 * @param int $z
	 *
	 * @return bool
	 */
	public function isChunkPopulated($x, $z){
		$chunk = $this->getChunk($x, $z);
		return $chunk !== null ? $chunk->isPopulated() : false;
	}

	/**
	 * @return Position
	 */
	public function getSpawnLocation(){
		return Position::fromObject($this->provider->getSpawn(), $this);
	}

	/**
	 * @param Vector3 $pos
	 */
	public function setSpawnLocation(Vector3 $pos){
		$previousSpawn = $this->getSpawnLocation();
		$this->provider->setSpawn($pos);
		$this->server->getPluginManager()->callEvent(new SpawnChangeEvent($this, $previousSpawn));
	}
	
	//public function requestChunk($x, $z, Player $player, $order = LevelProvider::ORDER_ZXY){
	public function requestChunk($x, $z, Player $player){
		$index = Level::chunkHash($x, $z);
		if(!isset($this->chunkSendQueue[$index])){
			$this->chunkSendQueue[$index] = [];
		}

		$this->chunkSendQueue[$index][spl_object_hash($player)] = $player;
	}

	protected function processChunkRequest(){
		if(count($this->chunkSendQueue) > 0){
			$protocols = [];
			$subClientsId = [];
			$x = null;
			$z = null;
			foreach($this->chunkSendQueue as $index => $players){
				if(isset($this->chunkSendTasks[$index])){
					continue;
				}
				Level::getXZ($index, $x, $z);
				foreach($players as $player){
					if($player->isConnected() && isset($player->usedChunks[$index])){
						$protocol = $player->getPlayerProtocol();
						$subClientId = $player->getSubClientId();
						if(advanced_cache === true){
							$playerIndex = "{$protocol}:{$subClientId}";
							$cache = Cache::get("world:" . $this->getId() . ":{$index}");
							if($cache !== false && isset($cache[$playerIndex])){
								$player->sendChunk($x, $z, $cache[$playerIndex]);
								continue;
							}
						}
						$protocols[$protocol] = $protocol;
						$subClientsId[$subClientId] = $subClientId;
					}
				}
				if($protocols !== []){
					$this->chunkSendTasks[$index] = true;
					$task = $this->provider->requestChunkTask($x, $z);
					if($task instanceof AsyncTask){
						$this->server->getScheduler()->scheduleAsyncTask($task);
					}
				}else{
					unset($this->chunkSendQueue[$index]);
				}
			}
		}
	}

	public function chunkRequestCallback($x, $z, $payload){
		if($this->closed){
			return false;
		} 
		$index = Level::chunkHash($x, $z);
		if(isset($this->chunkSendTasks[$index])){
			if(advanced_cache === true){
				$cacheId = "world:" . $this->getId() . ":{$index}";
				if(($cache = Cache::get($cacheId)) !== false){
					$payload = array_merge($cache, $payload);
				}
				Cache::add($cacheId, $payload, 60);
			}
			foreach($this->chunkSendQueue[$index] as $player){
				$playerIndex = $player->getPlayerProtocol() . ":" . $player->getSubClientId();
				if($player->isConnected() && isset($player->usedChunks[$index]) && isset($payload[$playerIndex])){
					$player->sendChunk($x, $z, $payload[$playerIndex]);
				}
			}
			unset($this->chunkSendQueue[$index]);
			unset($this->chunkSendTasks[$index]);
		}
	}

	/**
	 * Removes the entity from the level index
	 *
	 * @param Entity $entity
	 *
	 * @throws LevelException
	 */
	public function removeEntity(Entity $entity){
		if($entity->getLevel() !== $this){
			throw new LevelException("Invalid Entity level");
		}

		if($entity instanceof Player){
			unset($this->players[$entity->getId()]);
		}else{
			$entity->close();
		}

		unset($this->entities[$entity->getId()]);
		unset($this->updateEntities[$entity->getId()]);
	}

	/**
	 * @param Entity $entity
	 *
	 * @throws LevelException
	 */
	public function addEntity(Entity $entity){
		if($entity->getLevel() !== $this){
			throw new LevelException("Invalid Entity level");
		}
		if($entity instanceof Player){
			$this->players[$entity->getId()] = $entity;
		}
		$this->entities[$entity->getId()] = $entity;
	}

	/**
	 * @param Tile $tile
	 *
	 * @throws LevelException
	 */
	public function addTile(Tile $tile){
		if($tile->getLevel() !== $this){
			throw new LevelException("Invalid Tile level");
		}
		$this->tiles[$tile->getId()] = $tile;
		$this->chunkCacheClear($tile->getX() >> 4, $tile->getZ() >> 4);
	}

	/**
	 * @param Tile $tile
	 *
	 * @throws LevelException
	 */
	public function removeTile(Tile $tile){
		if($tile->getLevel() !== $this){
			throw new LevelException("Invalid Tile level");
		}

		unset($this->tiles[$tile->getId()]);
		unset($this->updateTiles[$tile->getId()]);
		$this->chunkCacheClear($tile->getX() >> 4, $tile->getZ() >> 4);
	}

	/**
	 * @param int $x
	 * @param int $z
	 *
	 * @return bool
	 */
	public function isChunkInUse($x, $z){
		$someIndex = Level::chunkHash($x, $z);
		return isset($this->usedChunks[$someIndex]) && count($this->usedChunks[$someIndex]) > 0;
	}

	/**
	 * @param int  $x
	 * @param int  $z
	 * @param bool $generate
	 *
	 * @return bool
	 */
	public function loadChunk($x, $z, $generate = true){
		if(isset($this->chunks[$index = Level::chunkHash($x, $z)])){
			return true;
		}
		$this->cancelUnloadChunkRequest($x, $z);
		$chunk = $this->provider->getChunk($x, $z, $generate);
		if($chunk !== null){
			$this->chunks[$index] = $chunk;
			$chunk->initChunk();
		}else{
			$this->provider->loadChunk($x, $z, $generate);
			if(($chunk = $this->provider->getChunk($x, $z)) !== null){
				$this->chunks[$index] = $chunk;
				$chunk->initChunk();
			}else{
				return false;
			}
		}
		$this->server->getPluginManager()->callEvent(new ChunkLoadEvent($chunk, !$chunk->isGenerated()));
		if(!$chunk->isLightPopulated() && $chunk->isPopulated() && $this->getServer()->getProperty("chunk-ticking.light-updates", false)){
			$this->getServer()->getScheduler()->scheduleAsyncTask(new LightPopulationTask($this, $chunk));
		}
		return true;
	}

	protected function queueUnloadChunk($x, $z){
		$this->unloadQueue[$index = Level::chunkHash($x, $z)] = microtime(true);
		unset($this->chunkTickList[$index]);
	}

	public function unloadChunkRequest($x, $z, $safe = true){
		if(($safe && $this->isChunkInUse($x, $z)) || $this->isSpawnChunk($x, $z)){
			return false;
		}

		$this->queueUnloadChunk($x, $z);

		return true;
	}

	public function cancelUnloadChunkRequest($x, $z){
		unset($this->unloadQueue[Level::chunkHash($x, $z)]);
	}

	public function unloadChunk($x, $z, $safe = true){
		if($this->isFrozen || ($safe && $this->isChunkInUse($x, $z))){
			return false;
		}
		$index = Level::chunkHash($x, $z);
		if(isset($this->chunks[$index])){
			$chunk = $this->chunks[$index];
		}else{
			unset($this->chunks[$index]);
			unset($this->usedChunks[$index]);
			unset($this->chunkTickList[$index]);
			Cache::remove("world:" . $this->getId() . ":$index");
			return true;
		}
		if($chunk !== null){
			if(!$chunk->allowUnload){
				return false;
			}
			$this->server->getPluginManager()->callEvent($ev = new ChunkUnloadEvent($chunk));
			if($ev->isCancelled()){
				return false;
			}
		}
		try{
			if($chunk !== null){
				if($this->getAutoSave()){
					$this->provider->setChunk($x, $z, $chunk);
					$this->provider->saveChunk($x, $z);
				}
			}
			$this->provider->unloadChunk($x, $z, $safe);
		}catch(\Exception $e){
			$konsol = $this->server->getLogger();
			$konsol->error("Error when unloading a chunk: " . $e->getMessage());
			if($konsol instanceof MainLogger){
				$konsol->logException($e);
			}
		}
		unset($this->chunks[$index]);
		unset($this->usedChunks[$index]);
		unset($this->chunkTickList[$index]);
		Cache::remove("world:" . $this->getId() . ":$index");
		return true;
	}

	/**
	 * @param int $X
	 * @param int $Z
	 *
	 * @return bool
	 */
	public function isSpawnChunk($X, $Z){
		$spawnX = $this->provider->getSpawn()->getX() >> 4;
		$spawnZ = $this->provider->getSpawn()->getZ() >> 4;

		return abs($X - $spawnX) <= 1 && abs($Z - $spawnZ) <= 1;
	}

	/**
	 * @deprecated
	 * @return Position
	 */
	public function getSpawn(){
		return $this->getSpawnLocation();
	}

    /**
     * @param null $spawn
     * @return bool|Position
     */
	public function getSafeSpawn($spawn = null){
		if(!($spawn instanceof Vector3) || $spawn->y < 1){
			$spawn = $this->getSpawnLocation();
		}
		if($spawn instanceof Vector3){
			$v = $spawn->floor();
			$chunk = $this->getChunk($v->x >> 4, $v->z >> 4, false);
			$x = $v->x & 0x0f;
			$z = $v->z & 0x0f;
			if($chunk !== null){
				for(; $v->y > 0; --$v->y){
					if($v->y < ($this->getMaxY() - 1) && Block::$solid[$chunk->getBlockId($x, $v->y & $this->getYMask(), $z)]){
						$v->y++;
						break;
					}
				}
				for(; $v->y < $this->getMaxY(); ++$v->y){
					if(!Block::$solid[$chunk->getBlockId($x, $v->y + 1, $z)]){
						if(!Block::$solid[$chunk->getBlockId($x, $v->y, $z)]){
							return new Position($spawn->x, $v->y === Math::floorFloat($spawn->y) ? $spawn->y : $v->y + 0.1, $spawn->z, $this);
						}
					}else{
						++$v->y;
					}
				}
			}
			return new Position($spawn->x, $v->y + 0.1, $spawn->z, $this);
		}
		return false;
	}

	/**
	 * @param Vector3 $pos
	 *
	 * @deprecated
	 */
	public function setSpawn(Vector3 $pos){
		$this->setSpawnLocation($pos);
	}

	/**
	 * @return int
	 */
	public function getTime(){
		return (int) $this->time;
	}

	/**
	 * @return string
	 */
	public function getName(){
		return $this->provider->getName();
	}

	/**
	 * @return string
	 */
	public function getFolderName(){
		return $this->folderName;
	}

	/**
	 * @param int $time
	 */
	public function setTime($time){
		$this->time = (int) $time;
		$this->sendTime();
	}
	
	public function stopTime(){
		$this->stopTime = true;
		$this->sendTime();
	}
	
	public function startTime(){
		$this->stopTime = false;
		$this->sendTime();
	}

	/**
	 * @return int
	 */
	public function getSeed(){
		return $this->provider->getSeed();
	}

	/**
	 * @param int $seed
	 */
	public function setSeed($seed){
		$this->provider->setSeed($seed);
	}
	
	public function generateChunk($x, $z, $force = false){
		if(count($this->chunkGenerationQueue) >= $this->chunkGenerationQueueSize && !$force){
			return;
		}
		if(!isset($this->chunkGenerationQueue[$index = Level::chunkHash($x, $z)])){
			$this->chunkGenerationQueue[$index] = true;
			$task = new GenerationTask($this, $this->getChunk($x, $z, true));
			$this->server->getScheduler()->scheduleAsyncTask($task);
		}
	}
	
	public function registerGenerator(){
		$size = $this->server->getScheduler()->getAsyncTaskPoolSize();
		for($i = 0; $i < $size; ++$i){
			$this->server->getScheduler()->scheduleAsyncTaskToWorker(new GeneratorRegisterTask($this, $this->generatorInstance), $i);
		}
	}

	public function unregisterGenerator(){
		$size = $this->server->getScheduler()->getAsyncTaskPoolSize();
		for($i = 0; $i < $size; ++$i){
			$this->server->getScheduler()->scheduleAsyncTaskToWorker(new GeneratorUnregisterTask($this), $i);
		}
	}

	public function regenerateChunk($x, $z){
		$this->unloadChunk($x, $z, false);

		$this->cancelUnloadChunkRequest($x, $z);

		$this->generateChunk($x, $z);
	}

	public function doChunkGarbageCollection(){
		if(!$this->isFrozen){
			$X = null;
			$Z = null;

			foreach($this->chunks as $index => $chunk){
				if(!isset($this->unloadQueue[$index]) && (!isset($this->usedChunks[$index]) || count($this->usedChunks[$index]) === 0)){
					Level::getXZ($index, $X, $Z);
					if(!$this->isSpawnChunk($X, $Z)){
						$this->unloadChunkRequest($X, $Z, true);
					}
				}
			}

			foreach($this->provider->getLoadedChunks() as $chunk){
				if(!isset($this->chunks[Level::chunkHash($chunk->getX(), $chunk->getZ())])){
					$this->provider->unloadChunk($chunk->getX(), $chunk->getZ(), false);
				}
			}

			$this->provider->doGarbageCollection();
		}
	}

	protected function unloadChunks(){
		if(count($this->unloadQueue) > 0 && !$this->isFrozen){
			$X = null;
			$Z = null;
			foreach($this->unloadQueue as $index => $time){
				Level::getXZ($index, $X, $Z);
				if($this->unloadChunk($X, $Z, true)){
					unset($this->unloadQueue[$index]);
				}
			}
		}
	}
		
	public function freezeMap(){
		$this->isFrozen = true;
	}

	public function unfreezeMap(){
		$this->isFrozen = false;
	}
	
	public function setMetadata($metadataKey, MetadataValue $metadataValue){
		$this->server->getLevelMetadata()->setMetadata($this, $metadataKey, $metadataValue);
	}

	public function getMetadata($metadataKey){
		return $this->server->getLevelMetadata()->getMetadata($this, $metadataKey);
	}

	public function hasMetadata($metadataKey){
		return $this->server->getLevelMetadata()->hasMetadata($this, $metadataKey);
	}

	public function removeMetadata($metadataKey, Plugin $plugin){
		$this->server->getLevelMetadata()->removeMetadata($this, $metadataKey, $plugin);
	}

	public function addEntityMotion($viewers, $entityId, $x, $y, $z){
		$motion = [$entityId, $x, $y, $z];
		foreach($viewers as $p){
			$subClientId = $p->getSubClientId();
			if($subClientId > 0 && ($parent = $p->getParent()) !== null){
				$playerIdentifier = $parent->getIdentifier();
			}else{
				$playerIdentifier = $p->getIdentifier();
			}
			
			if(!isset($this->motionToSend[$playerIdentifier])){
				$this->motionToSend[$playerIdentifier] = [
					'data' => [],
					'playerProtocol' => $p->getPlayerProtocol()
				];
			}
			$motion[4] = $subClientId;
			$this->motionToSend[$playerIdentifier]['data'][] = $motion;
		}
	}

	public function addEntityMovement($viewers, $entityId, $x, $y, $z, $yaw, $pitch, $headYaw = null, $isPlayer = false){
		$move = [$entityId, $x, $y, $z, $yaw, $headYaw === null ? $yaw : $headYaw, $pitch, $isPlayer];
		foreach($viewers as $p){
			$subClientId = $p->getSubClientId();
			if($subClientId > 0 && ($parent = $p->getParent()) !== null){
				$playerIdentifier = $parent->getIdentifier();
			}else{
				$playerIdentifier = $p->getIdentifier();
			}
			if(!isset($this->moveToSend[$playerIdentifier])){
				$this->moveToSend[$playerIdentifier] = [
					'data' => [],
					'playerProtocol' => $p->getPlayerProtocol()
				];
			}
			$move[8] = $subClientId;
			$this->moveToSend[$playerIdentifier]['data'][] = $move;
		}
	}

    /**
     * @param Player $sender
     * @param $recipient
     */
	public function addPlayerHandItem(Player $sender, $recipient){
		if(!isset($this->playerHandItemQueue[$sender->getId()])){
			$this->playerHandItemQueue[$sender->getId()] = [];
		}
		$this->playerHandItemQueue[$sender->getId()][$recipient->getId()] = [
			'sender' => $sender,
			'recipient' => $recipient,
			'time' => microtime(true)
		];
	}
	
	public function mayAddPlayerHandItem(Player $sender, $recipient){
		if(isset($this->playerHandItemQueue[$sender->getId()][$recipient->getId()])){
			return false;
		}
		
		return true;
	}
	
	public function populateChunk($x, $z, $force = false){
		if(isset($this->chunkPopulationQueue[$index = Level::chunkHash($x, $z)]) || (count($this->chunkPopulationQueue) >= $this->chunkPopulationQueueSize && !$force)){
			return false;
		}
		$chunk = $this->getChunk($x, $z, true);
		if(!$chunk->isPopulated()){
			$populate = true;
			for($xx = -1; $xx <= 1; ++$xx){
				for($zz = -1; $zz <= 1; ++$zz){
					if(isset($this->chunkPopulationLock[Level::chunkHash($x + $xx, $z + $zz)])){
						$populate = false;
						break;
					}
				}
			}
			if($populate){
				if(!isset($this->chunkPopulationQueue[$index])){
					$this->chunkPopulationQueue[$index] = true;
					for($xx = -1; $xx <= 1; ++$xx){
						for($zz = -1; $zz <= 1; ++$zz){
							$this->chunkPopulationLock[Level::chunkHash($x + $xx, $z + $zz)] = true;
						}
					}
					$task = new PopulationTask($this, $chunk);
					$this->server->getScheduler()->scheduleAsyncTask($task);
				}
			}
			return false;
		}
		return true;
	}
	
	public function updateChunk($x, $z){
		$players = $this->getUsingChunk($x, $z);
		if(empty($players)){
			return false;
		}
		$index = Level::chunkHash($x, $z);
		$this->chunkSendTasks[$index] = true;
		$this->chunkSendQueue[$index] = [];
		$protocols = [];
		$subClientsId = [];
		foreach($players as $p){
			$this->chunkSendQueue[$index][spl_object_hash($p)] = $p;
			$protocol = $p->getPlayerProtocol();
			if(!isset($protocols[$protocol])){
				$protocols[$protocol] = $protocol;
			}
			$subClientId = $p->getSubClientId();
			if(!isset($subClientsId[$subClientId])){
				$subClientsId[$subClientId] = $subClientId;
			}
		}
		$this->provider->requestChunkTask($x, $z);
	}
	
	public function getYMask(){
		return $this->yMask;
	}
	
	public function getMaxY(){
		return $this->maxY;
	}
	
	public function getX(){
		
	}
	
	public function getZ(){
		
	}
	
}
