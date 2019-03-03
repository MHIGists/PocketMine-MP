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

/**
 * All the Item classes
 */
namespace pocketmine\item;

use Ds\Set;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockToolType;
use pocketmine\entity\Entity;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\math\Vector3;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\utils\Binary;
use function array_map;
use function base64_decode;
use function base64_encode;
use function file_get_contents;
use function get_class;
use function gettype;
use function hex2bin;
use function is_string;
use function json_decode;
use const DIRECTORY_SEPARATOR;

class Item implements ItemIds, \JsonSerializable{
	public const TAG_ENCH = "ench";
	public const TAG_DISPLAY = "display";
	public const TAG_BLOCK_ENTITY_TAG = "BlockEntityTag";

	public const TAG_DISPLAY_NAME = "Name";
	public const TAG_DISPLAY_LORE = "Lore";


	/** @var LittleEndianNbtSerializer */
	private static $cachedParser = null;

	/**
	 * @param string $tag
	 *
	 * @return CompoundTag
	 * @throws NbtDataException
	 */
	private static function parseCompoundTag(string $tag) : CompoundTag{
		if(self::$cachedParser === null){
			self::$cachedParser = new LittleEndianNbtSerializer();
		}

		return self::$cachedParser->read($tag);
	}

	private static function writeCompoundTag(CompoundTag $tag) : string{
		if(self::$cachedParser === null){
			self::$cachedParser = new LittleEndianNbtSerializer();
		}

		return self::$cachedParser->write($tag);
	}

	/**
	 * Returns a new Item instance with the specified ID, damage, count and NBT.
	 *
	 * This function redirects to {@link ItemFactory#get}.
	 *
	 * @param int         $id
	 * @param int         $meta
	 * @param int         $count
	 * @param CompoundTag $tags
	 *
	 * @return Item
	 */
	public static function get(int $id, int $meta = 0, int $count = 1, ?CompoundTag $tags = null) : Item{
		return ItemFactory::get($id, $meta, $count, $tags);
	}

	/**
	 * Tries to parse the specified string into Item types.
	 *
	 * This function redirects to {@link ItemFactory#fromString}.
	 *
	 * @param string $str
	 *
	 * @return Item
	 * @throws \InvalidArgumentException
	 */
	public static function fromString(string $str) : Item{
		return ItemFactory::fromString($str);
	}


	/** @var Item[] */
	private static $creative = [];

	public static function initCreativeItems(){
		self::clearCreativeItems();

		$creativeItems = json_decode(file_get_contents(\pocketmine\RESOURCE_PATH . "vanilla" . DIRECTORY_SEPARATOR . "creativeitems.json"), true);

		foreach($creativeItems as $data){
			$item = Item::jsonDeserialize($data);
			if($item->getName() === "Unknown"){
				continue;
			}
			self::addCreativeItem($item);
		}
	}

	public static function clearCreativeItems(){
		Item::$creative = [];
	}

	public static function getCreativeItems() : array{
		return Item::$creative;
	}

	public static function addCreativeItem(Item $item){
		Item::$creative[] = clone $item;
	}

	public static function removeCreativeItem(Item $item){
		$index = self::getCreativeItemIndex($item);
		if($index !== -1){
			unset(Item::$creative[$index]);
		}
	}

	public static function isCreativeItem(Item $item) : bool{
		return Item::getCreativeItemIndex($item) !== -1;
	}

	/**
	 * @param int $index
	 *
	 * @return Item|null
	 */
	public static function getCreativeItem(int $index) : ?Item{
		return Item::$creative[$index] ?? null;
	}

	public static function getCreativeItemIndex(Item $item) : int{
		foreach(Item::$creative as $i => $d){
			if($item->equals($d, !($item instanceof Durable))){
				return $i;
			}
		}

		return -1;
	}

	/** @var int */
	protected $id;
	/** @var int */
	protected $meta;
	/** @var int */
	protected $count = 1;
	/** @var string */
	protected $name;

	//TODO: this stuff should be moved to itemstack properties, not mushed in with type properties

	/** @var EnchantmentInstance[] */
	protected $enchantments = [];
	/** @var string */
	protected $customName = "";
	/** @var string[] */
	protected $lore = [];
	/**
	 * TODO: this needs to die in a fire
	 * @var CompoundTag|null
	 */
	protected $blockEntityTag = null;

	/** @var Set|string[] */
	protected $canPlaceOn;
	/** @var Set|string[] */
	protected $canDestroy;

	/**
	 * Constructs a new Item type. This constructor should ONLY be used when constructing a new item TYPE to register
	 * into the index.
	 *
	 * NOTE: This should NOT BE USED for creating items to set into an inventory. Use {@link ItemFactory#get} for that
	 * purpose.
	 *
	 * @param int    $id
	 * @param int    $variant
	 * @param string $name
	 */
	public function __construct(int $id, int $variant = 0, string $name = "Unknown"){
		if($id < -0x8000 or $id > 0x7fff){ //signed short range
			throw new \InvalidArgumentException("ID must be in range " . -0x8000 . " - " . 0x7fff);
		}
		$this->id = $id;
		$this->meta = $variant !== -1 ? $variant & 0x7FFF : -1;
		$this->name = $name;

		$this->canPlaceOn = new Set();
		$this->canDestroy = new Set();
	}

	/**
	 * @return bool
	 */
	public function hasCustomBlockData() : bool{
		return $this->blockEntityTag !== null;
	}

	public function clearCustomBlockData(){
		$this->blockEntityTag = null;
		return $this;
	}

	/**
	 * @param CompoundTag $compound
	 *
	 * @return Item
	 */
	public function setCustomBlockData(CompoundTag $compound) : Item{
		$this->blockEntityTag = $compound;

		return $this;
	}

	/**
	 * @return CompoundTag|null
	 */
	public function getCustomBlockData() : ?CompoundTag{
		return $this->blockEntityTag;
	}

	/**
	 * @return bool
	 */
	public function hasEnchantments() : bool{
		return !empty($this->enchantments);
	}

	/**
	 * @param Enchantment $enchantment
	 * @param int         $level
	 *
	 * @return bool
	 */
	public function hasEnchantment(Enchantment $enchantment, int $level = -1) : bool{
		$id = $enchantment->getId();
		return isset($this->enchantments[$id]) and ($level === -1 or $this->enchantments[$id]->getLevel() === $level);
	}

	/**
	 * @param Enchantment $enchantment
	 *
	 * @return EnchantmentInstance|null
	 */
	public function getEnchantment(Enchantment $enchantment) : ?EnchantmentInstance{
		return $this->enchantments[$enchantment->getId()] ?? null;
	}

	/**
	 * @param Enchantment $enchantment
	 * @param int         $level
	 *
	 * @return Item
	 */
	public function removeEnchantment(Enchantment $enchantment, int $level = -1) : Item{
		$instance = $this->getEnchantment($enchantment);
		if($instance !== null and ($level === -1 or $instance->getLevel() === $level)){
			unset($this->enchantments[$enchantment->getId()]);
		}

		return $this;
	}

	/**
	 * @return Item
	 */
	public function removeEnchantments() : Item{
		$this->enchantments = [];
		return $this;
	}

	/**
	 * @param EnchantmentInstance $enchantment
	 *
	 * @return Item
	 */
	public function addEnchantment(EnchantmentInstance $enchantment) : Item{
		$this->enchantments[$enchantment->getId()] = $enchantment;
		return $this;
	}

	/**
	 * @return EnchantmentInstance[]
	 */
	public function getEnchantments() : array{
		return $this->enchantments;
	}

	/**
	 * Returns the level of the enchantment on this item with the specified ID, or 0 if the item does not have the
	 * enchantment.
	 *
	 * @param Enchantment $enchantment
	 *
	 * @return int
	 */
	public function getEnchantmentLevel(Enchantment $enchantment) : int{
		return ($instance = $this->getEnchantment($enchantment)) !== null ? $instance->getLevel() : 0;
	}

	/**
	 * @return bool
	 */
	public function hasCustomName() : bool{
		return $this->customName !== "";
	}

	/**
	 * @return string
	 */
	public function getCustomName() : string{
		return $this->customName;
	}

	/**
	 * @param string $name
	 *
	 * @return Item
	 */
	public function setCustomName(string $name) : Item{
		//TODO: encoding might need to be checked here
		$this->customName = $name;
		return $this;
	}

	/**
	 * @return Item
	 */
	public function clearCustomName() : Item{
		$this->setCustomName("");
		return $this;
	}

	/**
	 * @return string[]
	 */
	public function getLore() : array{
		return $this->lore;
	}

	/**
	 * @param string[] $lines
	 *
	 * @return Item
	 */
	public function setLore(array $lines) : Item{
		foreach($lines as $line){
			if(!is_string($line)){
				throw new \TypeError("Expected string[], but found " . gettype($line) . " in given array");
			}
		}
		$this->lore = $lines;
		return $this;
	}

	/**
	 * @return Set|string[]
	 */
	public function getCanPlaceOn() : Set{
		return $this->canPlaceOn;
	}

	/**
	 * @param Set|string[] $canPlaceOn
	 */
	public function setCanPlaceOn(Set $canPlaceOn) : void{
		$this->canPlaceOn = $canPlaceOn;
	}

	/**
	 * @return Set|string[]
	 */
	public function getCanDestroy() : Set{
		return $this->canDestroy;
	}

	/**
	 * @param Set|string[] $canDestroy
	 */
	public function setCanDestroy(Set $canDestroy) : void{
		$this->canDestroy = $canDestroy;
	}

	/**
	 * Returns whether this Item has a non-empty NBT.
	 * @return bool
	 */
	public function hasNamedTag() : bool{
		return $this->getNamedTag() !== null;
	}

	public function deserializeCompoundTag(CompoundTag $tag) : void{
		$display = $tag->getCompoundTag(self::TAG_DISPLAY);
		if($display !== null){
			$this->customName = $display->getString(self::TAG_DISPLAY_NAME, $this->customName, true);
			$lore = $tag->getListTag(self::TAG_DISPLAY_LORE);
			if($lore !== null and $lore->getTagType() === NBT::TAG_String){
				/** @var StringTag $t */
				foreach($lore as $t){
					$this->lore[] = $t->getValue();
				}
			}
		}else{
			$this->customName = "";
			$this->lore = [];
		}

		$this->removeEnchantments();
		$enchantments = $tag->getListTag(self::TAG_ENCH);
		if($enchantments !== null and $enchantments->getTagType() === NBT::TAG_Compound){
			/** @var CompoundTag $enchantment */
			foreach($enchantments as $enchantment){
				$magicNumber = $enchantment->getShort("id", 0, true);
				$level = $enchantment->getShort("lvl", 0, true);
				if($magicNumber <= 0 or $level <= 0){
					continue;
				}
				$type = Enchantment::getEnchantment($magicNumber);
				if($type !== null){
					$this->addEnchantment(new EnchantmentInstance($type, $level));
				}
			}
		}

		$this->blockEntityTag = $tag->getCompoundTag(self::TAG_BLOCK_ENTITY_TAG);

		$this->canPlaceOn = new Set();
		$canPlaceOn = $tag->getListTag("CanPlaceOn");
		if($canPlaceOn !== null){
			/** @var StringTag $tag */
			foreach($canPlaceOn as $entry){
				$this->canPlaceOn->add($entry->getValue());
			}
		}
		$this->canDestroy = new Set();
		$canDestroy = $tag->getListTag("CanDestroy");
		if($canDestroy !== null){
			/** @var StringTag $entry */
			foreach($canDestroy as $entry){
				$this->canDestroy->add($entry->getValue());
			}
		}
	}

	public function serializeCompoundTag(CompoundTag $tag) : void{
		$display = new CompoundTag(self::TAG_DISPLAY);
		if($this->hasCustomName()){
			$display->setString(self::TAG_DISPLAY_NAME, $this->getCustomName());
		}
		if(!empty($this->lore)){
			$loreTag = new ListTag(self::TAG_DISPLAY_LORE);
			foreach($this->lore as $line){
				$loreTag->push(new StringTag("", $line));
			}
		}
		if($display->count() > 0){
			$tag->setTag($display);
		}

		if($this->hasEnchantments()){
			$ench = new ListTag(self::TAG_ENCH);
			foreach($this->getEnchantments() as $enchantmentInstance){
				$ench->push(new CompoundTag("", [
					new ShortTag("id", $enchantmentInstance->getType()->getId()),
					new ShortTag("lvl", $enchantmentInstance->getLevel())
				]));
			}
			$tag->setTag($ench);
		}

		if($this->hasCustomBlockData()){
			$blockEntityTag = clone $this->getCustomBlockData();
			$blockEntityTag->setName(self::TAG_BLOCK_ENTITY_TAG);
			$tag->setTag($blockEntityTag);
		}

		if(!$this->canPlaceOn->isEmpty()){
			$canPlaceOn = new ListTag("CanPlaceOn");
			foreach($this->canPlaceOn as $item){
				$canPlaceOn->push(new StringTag("", $item));
			}
			$tag->setTag($canPlaceOn);
		}
		if(!$this->canDestroy->isEmpty()){
			$canDestroy = new ListTag("CanDestroy");
			foreach($this->canDestroy as $item){
				$canDestroy->push(new StringTag("", $item));
			}
			$tag->setTag($canDestroy);
		}
	}

	/**
	 * Returns a tree of Tag objects representing the Item in a serialized state, used for save data in some formats.
	 *
	 * @return CompoundTag|null
	 */
	public function getNamedTag() : ?CompoundTag{
		$compound = new CompoundTag();
		$this->serializeCompoundTag($compound);
		return $compound->count() > 0 ? $compound : null;
	}

	/**
	 * @return int
	 */
	public function getCount() : int{
		return $this->count;
	}

	/**
	 * @param int $count
	 *
	 * @return Item
	 */
	public function setCount(int $count) : Item{
		$this->count = $count;

		return $this;
	}

	/**
	 * Pops an item from the stack and returns it, decreasing the stack count of this item stack by one.
	 *
	 * @param int $count
	 *
	 * @return Item
	 * @throws \InvalidArgumentException if trying to pop more items than are on the stack
	 */
	public function pop(int $count = 1) : Item{
		if($count > $this->count){
			throw new \InvalidArgumentException("Cannot pop $count items from a stack of $this->count");
		}

		$item = clone $this;
		$item->count = $count;

		$this->count -= $count;

		return $item;
	}

	public function isNull() : bool{
		return $this->count <= 0 or $this->id === Item::AIR;
	}

	/**
	 * Returns the name of the item, or the custom name if it is set.
	 * @return string
	 */
	final public function getName() : string{
		return $this->hasCustomName() ? $this->getCustomName() : $this->getVanillaName();
	}

	/**
	 * Returns the vanilla name of the item, disregarding custom names.
	 * @return string
	 */
	public function getVanillaName() : string{
		return $this->name;
	}

	/**
	 * @return bool
	 */
	final public function canBePlaced() : bool{
		return $this->getBlock()->canBePlaced();
	}

	/**
	 * Returns the block corresponding to this Item.
	 * @return Block
	 */
	public function getBlock() : Block{
		return BlockFactory::get(Block::AIR);
	}

	/**
	 * @return int
	 */
	final public function getId() : int{
		return $this->id;
	}

	/**
	 * @return int
	 */
	public function getMeta() : int{
		return $this->meta;
	}

	/**
	 * Returns whether this item can match any item with an equivalent ID with any meta value.
	 * Used in crafting recipes which accept multiple variants of the same item, for example crafting tables recipes.
	 *
	 * @return bool
	 */
	public function hasAnyDamageValue() : bool{
		return $this->meta === -1;
	}

	/**
	 * Returns the highest amount of this item which will fit into one inventory slot.
	 * @return int
	 */
	public function getMaxStackSize() : int{
		return 64;
	}

	/**
	 * Returns the time in ticks which the item will fuel a furnace for.
	 * @return int
	 */
	public function getFuelTime() : int{
		return 0;
	}

	/**
	 * Returns how many points of damage this item will deal to an entity when used as a weapon.
	 * @return int
	 */
	public function getAttackPoints() : int{
		return 1;
	}

	/**
	 * Returns how many armor points can be gained by wearing this item.
	 * @return int
	 */
	public function getDefensePoints() : int{
		return 0;
	}

	/**
	 * Returns what type of block-breaking tool this is. Blocks requiring the same tool type as the item will break
	 * faster (except for blocks requiring no tool, which break at the same speed regardless of the tool used)
	 *
	 * @return int
	 */
	public function getBlockToolType() : int{
		return BlockToolType::TYPE_NONE;
	}

	/**
	 * Returns the harvesting power that this tool has. This affects what blocks it can mine when the tool type matches
	 * the mined block.
	 * This should return 1 for non-tiered tools, and the tool tier for tiered tools.
	 *
	 * @see Block::getToolHarvestLevel()
	 *
	 * @return int
	 */
	public function getBlockToolHarvestLevel() : int{
		return 0;
	}

	public function getMiningEfficiency(bool $isCorrectTool) : float{
		return 1;
	}

	/**
	 * Called when a player uses this item on a block.
	 *
	 * @param Player  $player
	 * @param Block   $blockReplace
	 * @param Block   $blockClicked
	 * @param int     $face
	 * @param Vector3 $clickVector
	 *
	 * @return ItemUseResult
	 */
	public function onActivate(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector) : ItemUseResult{
		return ItemUseResult::NONE();
	}

	/**
	 * Called when a player uses the item on air, for example throwing a projectile.
	 * Returns whether the item was changed, for example count decrease or durability change.
	 *
	 * @param Player  $player
	 * @param Vector3 $directionVector
	 *
	 * @return ItemUseResult
	 */
	public function onClickAir(Player $player, Vector3 $directionVector) : ItemUseResult{
		return ItemUseResult::NONE();
	}

	/**
	 * Called when a player is using this item and releases it. Used to handle bow shoot actions.
	 * Returns whether the item was changed, for example count decrease or durability change.
	 *
	 * @param Player $player
	 *
	 * @return ItemUseResult
	 */
	public function onReleaseUsing(Player $player) : ItemUseResult{
		return ItemUseResult::NONE();
	}

	/**
	 * Called when this item is used to destroy a block. Usually used to update durability.
	 *
	 * @param Block $block
	 *
	 * @return bool
	 */
	public function onDestroyBlock(Block $block) : bool{
		return false;
	}

	/**
	 * Called when this item is used to attack an entity. Usually used to update durability.
	 *
	 * @param Entity $victim
	 *
	 * @return bool
	 */
	public function onAttackEntity(Entity $victim) : bool{
		return false;
	}

	/**
	 * Returns the number of ticks a player must wait before activating this item again.
	 *
	 * @return int
	 */
	public function getCooldownTicks() : int{
		return 0;
	}

	/**
	 * Compares an Item to this Item and check if they match.
	 *
	 * @param Item $item
	 * @param bool $checkDamage Whether to verify that the damage values match.
	 * @param bool $checkCompound Whether to verify that the items' NBT match.
	 *
	 * @return bool
	 */
	final public function equals(Item $item, bool $checkDamage = true, bool $checkCompound = true) : bool{
		if($this->id === $item->getId() and (!$checkDamage or $this->getMeta() === $item->getMeta())){
			if($checkCompound){
				$tag1 = $this->getNamedTag();
				$tag2 = $item->getNamedTag();
				
				return ($tag1 === null and $tag2 === null) or ($tag1 !== null and $tag2 !== null and $tag1->equals($tag2));
			}else{
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns whether the specified item stack has the same ID, damage, NBT and count as this item stack.
	 *
	 * @param Item $other
	 *
	 * @return bool
	 */
	final public function equalsExact(Item $other) : bool{
		return $this->equals($other, true, true) and $this->count === $other->count;
	}

	/**
	 * @return string
	 */
	final public function __toString() : string{
		return "Item " . $this->name . " (" . $this->id . ":" . ($this->hasAnyDamageValue() ? "?" : $this->getMeta()) . ")x" . $this->count . (($tag = $this->getNamedTag()) !== null ? " tags:0x" . self::writeCompoundTag($tag) : "");
	}

	/**
	 * Returns an array of item stack properties that can be serialized to json.
	 *
	 * @return array
	 */
	final public function jsonSerialize() : array{
		$data = [
			"id" => $this->getId()
		];

		if($this->getMeta() !== 0){
			$data["damage"] = $this->getMeta();
		}

		if($this->getCount() !== 1){
			$data["count"] = $this->getCount();
		}

		if(($tag = $this->getNamedTag()) !== null){
			$data["nbt_b64"] = base64_encode(self::writeCompoundTag($tag));
		}

		return $data;
	}

	/**
	 * Returns an Item from properties created in an array by {@link Item#jsonSerialize}
	 *
	 * @param array $data
	 *
	 * @return Item
	 */
	final public static function jsonDeserialize(array $data) : Item{
		$nbt = "";

		//Backwards compatibility
		if(isset($data["nbt"])){
			$nbt = $data["nbt"];
		}elseif(isset($data["nbt_hex"])){
			$nbt = hex2bin($data["nbt_hex"]);
		}elseif(isset($data["nbt_b64"])){
			$nbt = base64_decode($data["nbt_b64"], true);
		}
		return ItemFactory::get(
			(int) $data["id"], (int) ($data["damage"] ?? 0), (int) ($data["count"] ?? 1), $nbt !== "" ? self::parseCompoundTag($nbt) : null
		);
	}

	/**
	 * Serializes the item to an NBT CompoundTag
	 *
	 * @param int    $slot optional, the inventory slot of the item
	 * @param string $tagName the name to assign to the CompoundTag object
	 *
	 * @return CompoundTag
	 */
	public function nbtSerialize(int $slot = -1, string $tagName = "") : CompoundTag{
		$result = new CompoundTag($tagName, [
			new ShortTag("id", $this->id),
			new ByteTag("Count", Binary::signByte($this->count)),
			new ShortTag("Damage", $this->getMeta())
		]);

		if(($itemNBT = $this->getNamedTag()) !== null){
			$itemNBT->setName("tag");
			$result->setTag($itemNBT);
		}

		if($slot !== -1){
			$result->setByte("Slot", $slot);
		}

		return $result;
	}

	/**
	 * Deserializes an Item from an NBT CompoundTag
	 *
	 * @param CompoundTag $tag
	 *
	 * @return Item
	 */
	public static function nbtDeserialize(CompoundTag $tag) : Item{
		if(!$tag->hasTag("id") or !$tag->hasTag("Count")){
			return ItemFactory::get(0);
		}

		$count = Binary::unsignByte($tag->getByte("Count"));
		$meta = $tag->getShort("Damage", 0);

		$idTag = $tag->getTag("id");
		if($idTag instanceof ShortTag){
			$item = ItemFactory::get($idTag->getValue(), $meta, $count);
		}elseif($idTag instanceof StringTag){ //PC item save format
			try{
				$item = ItemFactory::fromString($idTag->getValue() . ":$meta");
			}catch(\InvalidArgumentException $e){
				//TODO: improve error handling
				return ItemFactory::air();
			}
			$item->setCount($count);
		}else{
			throw new \InvalidArgumentException("Item CompoundTag ID must be an instance of StringTag or ShortTag, " . get_class($idTag) . " given");
		}

		$itemNBT = $tag->getCompoundTag("tag");
		if($itemNBT instanceof CompoundTag){
			/** @var CompoundTag $t */
			//TODO: exception handling on bad data
			$item->deserializeCompoundTag($itemNBT);
		}

		return $item;
	}

	public function __clone(){
		$this->enchantments = array_map(function($e){ return clone $e; }, $this->enchantments);
		if($this->blockEntityTag !== null){
			$this->blockEntityTag = clone $this->blockEntityTag;
		}
		$this->canDestroy = $this->canDestroy->copy();
		$this->canPlaceOn = $this->canPlaceOn->copy();
	}
}
