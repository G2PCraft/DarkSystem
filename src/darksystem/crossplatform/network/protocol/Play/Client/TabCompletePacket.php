<?php
/**
 *  ______  __         ______               __    __
 * |   __ \|__|.-----.|   __ \.----..-----.|  |_ |  |--..-----..----.
 * |   __ <|  ||  _  ||   __ <|   _||  _  ||   _||     ||  -__||   _|
 * |______/|__||___  ||______/|__|  |_____||____||__|__||_____||__|
 *             |_____|
 *
 * BigBrother plugin for PocketMine-MP
 * Copyright (C) 2014-2015 shoghicp <https://github.com/shoghicp/BigBrother>
 * Copyright (C) 2016- BigBrotherTeam
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author BigBrotherTeam
 * @link   https://github.com/BigBrotherTeam/BigBrother
 *
 */

declare(strict_types=1);

namespace darksystem\crossplatform\network\protocol\Play\Client;

use darksystem\crossplatform\network\InboundPacket;

class TabCompletePacket extends InboundPacket{

	/** @var string */
	public $text;
	/** @var bool */
	public $assumeCommand;
	/** @var bool */
	public $hasPosition;
	/** @var int */
	public $x;
	/** @var int */
	public $y;
	/** @var int */
	public $z;

	public function pid() : {
		return self::TAB_COMPLETE_PACKET;
	}

	protected function decode() : {
		$this->text = $this->getString();
		$this->assumeCommand = $this->getBool();
		$this->hasPosition = $this->getBool();
		if($this->hasPosition){
			$this->getPosition($this->x, $this->y, $this->z);
		}
	}
}