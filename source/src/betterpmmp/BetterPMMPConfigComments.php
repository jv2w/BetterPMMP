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
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;
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
 * Both entry points normalize CRLF line endings and expand any marker lines still present, so files
 * written raw by a CRLF-damaged template self-heal on the next startup.
 */
final class BetterPMMPConfigComments{

	private const MARKER_PATTERN = '/^([ \t]*)#!\s*(\S+)[ \t]*$/m';

	/**
	 * [BetterPMMP-PATCH] Records which language the comments in a rendered file are written in, so
	 * {@link retranslate()} can answer "is this already current?" without loading every shipped language.
	 */
	private const LANGUAGE_STAMP_PATTERN = '/^#@ betterpmmp-lang: (\S+)[ \t]*\n?/m';

	private function __construct(){
		//NOOP
	}

	/** [BetterPMMP-PATCH] Expands `#! <key>` markers without stamping, for fragments spliced into an already-stamped file. */
	public static function expand(string $template, Language $lang) : string{
		$template = str_replace("\r\n", "\n", $template);
		$result = preg_replace_callback(
			self::MARKER_PATTERN,
			static fn(array $m) : string => self::block($m[2], $m[1], $lang),
			$template
		);
		return $result ?? $template;
	}

	public static function render(string $template, Language $lang) : string{
		return self::stamp(self::expand($template, $lang), $lang);
	}

	public static function retranslate(string $content, string $template, Language $current) : string{
		$content = str_replace("\r\n", "\n", $content);

		/** [BetterPMMP-PATCH] Fast path: the stamp says the comments are already in the current language,
		 * so skip the probe and the 13-language load in convert() entirely. Without this, deleting a single
		 * comment line makes the probe below miss forever - render() only expands `#!` markers, which are
		 * long gone from a rendered file, so the deleted line is never restored and the expensive path runs
		 * on every startup, silently (the output equals the input, so nothing is even written). */
		$stamped = preg_match(self::LANGUAGE_STAMP_PATTERN, $content, $stampMatch) === 1 ? $stampMatch[1] : null;
		if($stamped === $current->getLang()){
			return self::render($content, $current);
		}

		$map = self::markers($template);
		foreach($map as [$key, $indent]){
			$block = self::block($key, $indent, $current);
			if($block !== "" && strpos($content, $block) === false){
				return self::render(self::convert($content, $map, $current), $current);
			}
		}
		return self::render($content, $current);
	}

	private static function stamp(string $content, Language $lang) : string{
		$stripped = preg_replace(self::LANGUAGE_STAMP_PATTERN, '', $content) ?? $content;
		$code = $lang->getLang();
		return "#@ betterpmmp-lang: {$code}\n{$stripped}";
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
		if(preg_match_all(self::MARKER_PATTERN, str_replace("\r\n", "\n", $template), $matches, PREG_SET_ORDER) !== false){
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
