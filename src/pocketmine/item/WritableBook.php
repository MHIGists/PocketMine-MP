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

namespace pocketmine\item;

use Ds\Deque;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;

class WritableBook extends Item{

	public const TAG_PAGES = "pages"; //TAG_List<TAG_Compound>
	public const TAG_PAGE_TEXT = "text"; //TAG_String
	public const TAG_PAGE_PHOTONAME = "photoname"; //TAG_String - TODO

	/** @var WritableBookPage[]|Deque */
	private $pages;

	public function __construct(){
		parent::__construct(self::WRITABLE_BOOK, 0, "Book & Quill");
		$this->pages = new Deque();
	}

	/**
	 * Returns whether the given page exists in this book.
	 *
	 * @param int $pageId
	 *
	 * @return bool
	 */
	public function pageExists(int $pageId) : bool{
		return isset($this->pages[$pageId]);
	}

	/**
	 * Returns a string containing the content of a page (which could be empty), or null if the page doesn't exist.
	 *
	 * @param int $pageId
	 *
	 * @return string
	 * @throws \OutOfRangeException if requesting a nonexisting page
	 */
	public function getPageText(int $pageId) : string{
		return $this->pages[$pageId]->getText();
	}

	/**
	 * Sets the text of a page in the book. Adds the page if the page does not yet exist.
	 *
	 * @param int    $pageId
	 * @param string $pageText
	 *
	 * @return bool indicating whether the page was created or not.
	 */
	public function setPageText(int $pageId, string $pageText) : bool{
		$created = false;
		if(!$this->pageExists($pageId)){
			$this->addPage($pageId);
			$created = true;
		}

		$this->pages->set($pageId, new WritableBookPage($pageText));
		return $created;
	}

	/**
	 * Adds a new page with the given page ID.
	 * Creates a new page for every page between the given ID and existing pages that doesn't yet exist.
	 *
	 * @param int $pageId
	 */
	public function addPage(int $pageId) : void{
		if($pageId < 0){
			throw new \InvalidArgumentException("Page number \"$pageId\" is out of range");
		}

		for($current = $this->pages->count(); $current <= $pageId; $current++){
			$this->pages->push(new WritableBookPage(""));
		}
	}

	/**
	 * Deletes an existing page with the given page ID.
	 *
	 * @param int $pageId
	 *
	 * @return bool TODO: useless return value
	 */
	public function deletePage(int $pageId) : bool{
		$this->pages->remove($pageId);
		return true;
	}

	/**
	 * Inserts a new page with the given text and moves other pages upwards.
	 *
	 * @param int    $pageId
	 * @param string $pageText
	 *
	 * @return bool TODO: useless return value
	 */
	public function insertPage(int $pageId, string $pageText = "") : bool{
		$this->pages->insert($pageId, new WritableBookPage($pageText));
		return true;
	}

	/**
	 * Switches the text of two pages with each other.
	 *
	 * @param int $pageId1
	 * @param int $pageId2
	 *
	 * @return bool indicating success
	 * @throws \OutOfRangeException if either of the pages does not exist
	 */
	public function swapPages(int $pageId1, int $pageId2) : bool{
		$pageContents1 = $this->getPageText($pageId1);
		$pageContents2 = $this->getPageText($pageId2);
		$this->setPageText($pageId1, $pageContents2);
		$this->setPageText($pageId2, $pageContents1);

		return true;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	/**
	 * Returns an array containing all pages of this book.
	 *
	 * @return WritableBookPage[]|Deque
	 */
	public function getPages() : Deque{
		return $this->pages;
	}

	/**
	 * @param WritableBookPage[]|Deque $pages
	 */
	public function setPages(Deque $pages) : void{
		$this->pages = $pages;
	}

	public function deserializeCompoundTag(CompoundTag $tag) : void{
		parent::deserializeCompoundTag($tag);
		$this->pages = new Deque();

		$pages = $tag->getListTag(self::TAG_PAGES);
		if($pages !== null){
			/** @var CompoundTag $page */
			foreach($pages as $page){
				$this->pages->push(new WritableBookPage($page->getString(self::TAG_PAGE_TEXT), $page->getString(self::TAG_PAGE_PHOTONAME, "")));
			}
		}
	}

	public function serializeCompoundTag(CompoundTag $tag) : void{
		parent::serializeCompoundTag($tag);
		if(!$this->pages->isEmpty()){
			$pages = new ListTag(self::TAG_PAGES);
			foreach($this->pages as $page){
				$pages->push(new CompoundTag("", [
					new StringTag(self::TAG_PAGE_TEXT, $page->getText()),
					new StringTag(self::TAG_PAGE_PHOTONAME, $page->getPhotoName())
				]));
			}
			$tag->setTag($pages);
		}
	}

	public function __clone(){
		parent::__clone();
		$this->pages = $this->pages->map(function($e){ return clone $e; });
	}
}
