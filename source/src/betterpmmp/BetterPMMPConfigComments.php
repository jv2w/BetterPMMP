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

namespace pocketmine\betterpmmp;

use pocketmine\lang\Language;
use pocketmine\lang\LanguageNotFoundException;
use function array_map;
use function explode;
use function implode;
use function preg_match_all;
use function preg_replace_callback;
use function strpos;
use function strtr;
use const PREG_SET_ORDER;

/**
 * [BetterPMMP-PATCH]
 * Localizes the documentation comments of the server config files (pocketmine.yml, resource_packs.yml) to
 * the language chosen in the setup wizard, and re-localizes them when that language is changed in server.properties.
 *
 * The resource template marks each translatable comment with a `<indent>#! <translation-key>` line
 * (still a valid YAML comment). A key's localized value may span several lines via `\n`; each becomes
 * one `<indent># <text>` line. {@link render()} expands the markers on first launch. {@link retranslate()}
 * converts an already-rendered file from whatever language its comments are in to the current one by a
 * single non-overlapping string replacement, so it never touches value lines - user edits are preserved.
 */
final class BetterPMMPConfigComments{

	private const MARKER_PATTERN = '/^([ \t]*)#!\s*(\S+)[ \t]*$/m';

	private function __construct(){
		//NOOP
	}

	public static function render(string $template, Language $lang) : string{
		$result = preg_replace_callback(
			self::MARKER_PATTERN,
			static fn(array $m) : string => self::block($m[2], $m[1], $lang),
			$template
		);
		return $result ?? $template;
	}

	public static function retranslate(string $content, string $template, Language $current) : string{
		$map = self::markers($template);
		foreach($map as [$key, $indent]){
			$block = self::block($key, $indent, $current);
			if($block !== "" && strpos($content, $block) === false){
				return self::convert($content, $map, $current);
			}
		}
		return $content;
	}

	/**
	 * @param list<array{string, string}> $map
	 */
	private static function convert(string $content, array $map, Language $current) : string{
		$pairs = [];
		$currentCode = $current->getLang();
		foreach(Language::getLanguageList() as $code => $name){
			if($code === $currentCode){
				continue;
			}
			try{
				$other = new Language($code);
			}catch(LanguageNotFoundException){
				continue;
			}
			foreach($map as [$key, $indent]){
				$old = self::block($key, $indent, $other);
				$new = self::block($key, $indent, $current);
				if($old !== "" && $new !== "" && $old !== $new){
					$pairs[$old] = $new;
				}
			}
		}
		return $pairs === [] ? $content : strtr($content, $pairs);
	}

	/**
	 * @return list<array{string, string}>
	 */
	private static function markers(string $template) : array{
		$map = [];
		if(preg_match_all(self::MARKER_PATTERN, $template, $matches, PREG_SET_ORDER) !== false){
			foreach($matches as $m){
				$map[] = [$m[2], $m[1]];
			}
		}
		return $map;
	}

	private static function block(string $key, string $indent, Language $lang) : string{
		$text = $lang->get($key);
		if($text === $key){
			return "";
		}
		return implode("\n", array_map(static fn(string $line) : string => $indent . "# " . $line, explode("\n", $text)));
	}
}
