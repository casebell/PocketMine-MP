<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

#include <rules/DataPacket.h>

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\serializer\NetworkBinaryStream;
use pocketmine\network\mcpe\serializer\NetworkNbtSerializer;

class UpdateBlockPropertiesPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::UPDATE_BLOCK_PROPERTIES_PACKET;

	/** @var string */
	private $nbt;

	public static function create(CompoundTag $data) : self{
		$result = new self;
		$result->nbt = (new NetworkNbtSerializer())->write(new TreeRoot($data));
		return $result;
	}

	protected function decodePayload(NetworkBinaryStream $in) : void{
		$this->nbt = $in->getRemaining();
	}

	protected function encodePayload(NetworkBinaryStream $out) : void{
		$out->put($this->nbt);
	}

	public function handle(PacketHandler $handler) : bool{
		return $handler->handleUpdateBlockProperties($this);
	}
}
