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
	 * Used for stripping, so it deliberately matches a stamp on any line - a file that somehow collected
	 * more than one must come back out with exactly one.
	 */
	private const LANGUAGE_STAMP_PATTERN = '/^#@ betterpmmp-lang: (\S+)[ \t]*\n?/m';

	/**
	 * [BetterPMMP-PATCH] Reading the stamp is anchored to the first line instead. stamp() always writes it
	 * there, so a stamp found anywhere else was not written by us and must not be allowed to decide which
	 * language the file is in.
	 */
	private const LANGUAGE_STAMP_READ_PATTERN = '/\A#@ betterpmmp-lang: (\S+)[ \t]*$/m';

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

		/** [BetterPMMP-PATCH] Decide by evidence, not by the stamp alone. Counting how many of the current
		 * language's comment blocks are actually in the file separates the two cases the stamp cannot:
		 * a user who deleted a comment line (nearly all blocks still present - skip the 13-language load in
		 * convert(), because render() only expands `#!` markers and could never restore that line anyway)
		 * from a file whose stamp does not describe its contents at all (no blocks present - a hand-edited
		 * stamp, or a file written by a build whose conversion missed). The latter has to be converted, and
		 * trusting the stamp there left the file stuck in the wrong language forever. */
		$map = self::markers($template);
		$found = 0;
		$total = 0;
		foreach($map as [$key, $indent]){
			$block = self::block($key, $indent, $current);
			if($block === ""){
				continue;
			}
			$total++;
			if(strpos($content, $block) !== false){
				$found++;
			}
		}
		if($found === $total){
			return self::render($content, $current);
		}
		$stamped = preg_match(self::LANGUAGE_STAMP_READ_PATTERN, $content, $stampMatch) === 1 ? $stampMatch[1] : null;
		if($found > 0 && $stamped === $current->getLang()){
			return self::render($content, $current);
		}
		return self::render(self::convert($content, $map, $current), $current);
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
		/** [BetterPMMP-PATCH] The current language is no longer skipped: upstream PocketMine-MP writes the
		 * very same text as `#text`, so a config carried over from a vanilla build still needs the
		 * `#text` -> `# text` normalization even when its comments are already in the right language. */
		foreach(Language::getLanguageList() as $code => $name){
			try{
				$other = new Language($code);
			}catch(LanguageNotFoundException){
				continue;
			}
			foreach($map as [$key, $indent]){
				$new = self::block($key, $indent, $current);
				if($new === ""){
					continue;
				}
				foreach(self::blockForms($key, $indent, $other) as $old){
					if($old !== $new){
						$pairs[$old] = $new;
					}
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
		return self::renderBlock($key, $indent, $lang, "# ");
	}

	/**
	 * [BetterPMMP-PATCH] Every spelling of one key's comment block that may already be sitting in a config
	 * file. render() always writes `# text`, but upstream PocketMine-MP ships `#text`; both have to be
	 * recognized or a migrated config is never converted.
	 *
	 * @return list<string>
	 */
	private static function blockForms(string $key, string $indent, Language $lang) : array{
		$forms = [];
		foreach(["# ", "#"] as $prefix){
			$block = self::renderBlock($key, $indent, $lang, $prefix);
			if($block !== ""){
				$forms[] = $block;
			}
		}
		return $forms;
	}

	private static function renderBlock(string $key, string $indent, Language $lang, string $prefix) : string{
		$text = $lang->get($key);
		if($text === $key){
			return "";
		}
		return implode("\n", array_map(static fn(string $line) : string => $indent . $prefix . $line, explode("\n", $text)));
	}
}
