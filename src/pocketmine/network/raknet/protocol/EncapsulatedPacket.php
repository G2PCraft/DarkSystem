<?php

namespace pocketmine\network\raknet\protocol;

use pocketmine\network\raknet\Binary;

class EncapsulatedPacket{

    public $reliability;
    public $hasSplit = false;
    public $length = 0;
    public $messageIndex = null;
    public $orderIndex = null;
    public $orderChannel = null;
    public $splitCount = null;
    public $splitID = null;
    public $splitIndex = null;
    public $buffer;
    public $needACK = false;
    public $identifierACK = null;

    /**
     * @param string $binary
     * @param bool   $internal
     * @param int    &$offset
     *
     * @return EncapsulatedPacket
     */
    public static function fromBinary($binary, $internal = false, &$offset = null){
	    $packet = new EncapsulatedPacket();

        $flags = ord($binary{0});
        $packet->reliability = $reliability = ($flags & 0b11100000) >> 5;
        $packet->hasSplit = $hasSplit = ($flags & 0b00010000) > 0;
        if($internal){
            $length = Binary::readInt(substr($binary, 1, 4));
            $packet->identifierACK = Binary::readInt(substr($binary, 5, 4));
            $offset = 9;
        }else{
            $length = (int) ceil(Binary::readShort(substr($binary, 1, 2)) / 8);
            $offset = 3;
	        $packet->identifierACK = null;
		}
		
		if($reliability > 0){
			if($reliability >= 2 and $reliability !== 5){
				$packet->messageIndex = Binary::readLTriad(substr($binary, $offset, 3));
				$offset += 3;
			}

			if($reliability <= 4 and $reliability !== 2){
				$packet->orderIndex = Binary::readLTriad(substr($binary, $offset, 3));
				$offset += 3;
				$packet->orderChannel = ord($binary{$offset++});
			}
		}

        if($hasSplit){
            $packet->splitCount = Binary::readInt(substr($binary, $offset, 4));
            $offset += 4;
            $packet->splitID = Binary::readShort(substr($binary, $offset, 2));
            $offset += 2;
            $packet->splitIndex = Binary::readInt(substr($binary, $offset, 4));
            $offset += 4;
        }

        $packet->buffer = substr($binary, $offset, $length);
        $offset += $length;

        return $packet;
    }

    public function getTotalLength(){
        return 3 + strlen($this->buffer) + ($this->messageIndex !== null ? 3 : 0) + ($this->orderIndex !== null ? 4 : 0) + ($this->hasSplit ? 10 : 0);
    }

    /**
     * @param bool $internal
     *
     * @return string
     */
    public function toBinary($internal = false){
        return
			chr(($this->reliability << 5) | ($this->hasSplit ? 0b00010000 : 0)) .
			($internal ? Binary::writeInt(strlen($this->buffer)) . Binary::writeInt($this->identifierACK) : Binary::writeShort(strlen($this->buffer) << 3)) .
			($this->reliability > 0 ?
				(($this->reliability >= 2 and $this->reliability !== 5) ? Binary::writeLTriad($this->messageIndex) : "") .
				(($this->reliability <= 4 and $this->reliability !== 2) ? Binary::writeLTriad($this->orderIndex) . chr($this->orderChannel) : "")
				: ""
			) .
			($this->hasSplit ? Binary::writeInt($this->splitCount) . Binary::writeShort($this->splitID) . Binary::writeInt($this->splitIndex) : "")
			. $this->buffer;
    }

    public function __toString(){
        return $this->toBinary();
    }
}
