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

use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\lang\Language;
use function array_column;
use function array_is_list;
use function array_key_exists;
use function array_merge;
use function array_pop;
use function array_reverse;
use function array_slice;
use function array_splice;
use function count;
use function explode;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function usort;
use function var_export;
use function yaml_emit;
use function yaml_parse;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const YAML_UTF8_ENCODING;

/**
 * [BetterPMMP-PATCH]
 * Enforces the canonical BetterPMMP pocketmine.yml layout on servers whose file came from another build.
 *
 * When `better-pmmp.config.enforce-format` is true, {@link apply()} regenerates the whole file from the
 * resource template on every startup: comments and key order come from the template (localized through
 * {@link BetterPMMPConfigComments}), every value present in the old file is carried over, and keys the
 * template does not know keep their values but are re-emitted without comments at the end of their
 * section. When the flag is off (default), only the language retranslation pass runs, plus one repair:
 * a parseable file with no `better-pmmp` root section at all gets the rendered template section
 * appended (a file with no values at all is regenerated from the template), so servers migrating
 * from vanilla configs always gain the BetterPMMP settings surface.
 */
final class BetterPMMPConfigFormat{

	private const KEY_LINE_PATTERN = '/^( *)([A-Za-z0-9_][A-Za-z0-9_.-]*):[ \t]*(.*)$/';

	private function __construct(){
		//NOOP
	}

	public static function apply(string $content, string $template, Language $lang) : string{
		$content = str_replace("\r\n", "\n", $content);
		$template = str_replace("\r\n", "\n", $template);
		/** [BetterPMMP-PATCH] A truncated-to-empty file (crash mid-write, full disk) parses to null, which
		 * used to take the retranslate path and emit "" - leaving the server permanently running on
		 * defaults with a blank config. Regenerate from the template instead. */
		if(trim($content) === ""){
			return BetterPMMPConfigComments::render($template, $lang);
		}
		$data = self::parse($content);
		if($data === null){
			return BetterPMMPConfigComments::retranslate($content, $template, $lang);
		}
		if($data === []){
			return BetterPMMPConfigComments::render($template, $lang);
		}
		if(self::enforceEnabled($data)){
			return BetterPMMPConfigComments::render(self::rebuild($template, $data), $lang);
		}
		$result = BetterPMMPConfigComments::retranslate($content, $template, $lang);
		$root = explode('.', BetterPMMPProperties::CONFIG_ENFORCE_FORMAT)[0];
		if(!array_key_exists($root, $data)){
			return self::appendMissingRoot($result, $template, $root, $lang);
		}
		return self::fillMissingKeys($result, $template, $data, $root, $lang);
	}

	/**
	 * [BetterPMMP-PATCH] Adds the template keys the file does not carry yet, in place, leaving every existing
	 * line - values, key order and the user's own comments - untouched. Without this, a `better-pmmp` section
	 * written by an older build never gained the options a later build added: apply() only appended when the
	 * whole root was missing, so those servers silently ran on the inline defaults and never saw the new key
	 * or its localized comment, contradicting the promise that every option is documented in the file.
	 *
	 * @param array<int|string, mixed> $data
	 */
	private static function fillMissingKeys(string $content, string $template, array $data, string $root, Language $lang) : string{
		$missing = self::missingFragments($template, $data, $root);
		if($missing === []){
			return $content;
		}
		$lines = explode("\n", $content);
		$inserts = [];
		foreach($missing as $ordinal => [$path, $fragment]){
			$at = self::blockEnd($lines, $path);
			if($at === null){
				return $content;
			}
			$inserts[] = [$at, $ordinal, $fragment];
		}
		/** Apply back to front so the earlier insertion points stay valid; ties keep template order because
		 * the later fragment is spliced in first. */
		usort($inserts, static fn(array $a, array $b) : int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
		foreach($inserts as [$at, $ordinal, $fragment]){
			array_splice($lines, $at, 0, $fragment);
		}
		$result = BetterPMMPConfigComments::expand(implode("\n", $lines), $lang);
		return self::parse($result) === null ? $content : $result;
	}

	/**
	 * [BetterPMMP-PATCH] Every template subtree under $root that $data does not have, paired with the path of
	 * the parent it belongs under. Only subtrees whose parent exists are reported, so the insertion point is
	 * always findable.
	 *
	 * @param array<int|string, mixed> $data
	 * @return list<array{list<string>, list<string>}>
	 */
	private static function missingFragments(string $template, array $data, string $root) : array{
		$lines = explode("\n", str_replace("\r\n", "\n", $template));
		$start = null;
		foreach($lines as $i => $line){
			if($line === "{$root}:"){
				$start = $i;
				break;
			}
		}
		if($start === null){
			return [];
		}
		$missing = [];
		/** @phpstan-var list<array{int, string}> $stack */
		$stack = [];
		$pending = [];
		$count = count($lines);
		for($i = $start + 1; $i < $count; $i++){
			$line = $lines[$i];
			if(preg_match(self::KEY_LINE_PATTERN, $line, $m) !== 1){
				$pending[] = $line;
				continue;
			}
			$indent = strlen($m[1]);
			if($indent === 0){
				break;
			}
			while($stack !== [] && $stack[count($stack) - 1][0] >= $indent){
				array_pop($stack);
			}
			$parent = array_column($stack, 1);
			$path = $parent;
			$path[] = $m[2];
			$found = false;
			self::lookup($data, array_merge([$root], $path), $found);
			if($found){
				$stack[] = [$indent, $m[2]];
				$pending = [];
				continue;
			}
			$body = [$line];
			$last = 0;
			for($j = $i + 1; $j < $count; $j++){
				$next = $lines[$j];
				if(preg_match(self::KEY_LINE_PATTERN, $next, $n) === 1){
					if(strlen($n[1]) <= $indent){
						break;
					}
					$last = count($body);
				}
				$body[] = $next;
			}
			/** Cut the run of blank and comment lines that trails the subtree: those introduce the next key. */
			$tail = array_slice($body, $last + 1);
			$fragment = array_merge($pending, array_slice($body, 0, $last + 1));
			$missing[] = [array_merge([$root], $parent), $fragment];
			$pending = $tail;
			$i = $j - 1;
		}
		return $missing;
	}

	/**
	 * [BetterPMMP-PATCH] Index of the line just past the block $path names, i.e. where a new child of it goes.
	 *
	 * @param list<string> $lines
	 * @param list<string> $path
	 */
	private static function blockEnd(array $lines, array $path) : ?int{
		/** @phpstan-var list<array{int, string}> $stack */
		$stack = [];
		$depth = count($path);
		$end = null;
		$count = count($lines);
		for($i = 0; $i < $count; $i++){
			if(preg_match(self::KEY_LINE_PATTERN, $lines[$i], $m) !== 1){
				continue;
			}
			$indent = strlen($m[1]);
			while($stack !== [] && $stack[count($stack) - 1][0] >= $indent){
				array_pop($stack);
			}
			$stack[] = [$indent, $m[2]];
			if($end !== null){
				if(count($stack) <= $depth){
					return $end;
				}
				$end = $i + 1;
				continue;
			}
			if(count($stack) === $depth && array_column($stack, 1) === $path){
				$end = $i + 1;
			}
		}
		return $end;
	}

	private static function appendMissingRoot(string $content, string $template, string $root, Language $lang) : string{
		$lines = explode("\n", $template);
		$start = null;
		foreach($lines as $i => $line){
			if($line === "{$root}:"){
				$start = $i;
				break;
			}
		}
		if($start === null){
			return $content;
		}
		while($start > 0 && ($lines[$start - 1][0] ?? '') === '#'){
			$start--;
		}
		/** [BetterPMMP-PATCH] expand(), not render() - this fragment is spliced into a file that already
		 * carries the language stamp at the top, and a second stamp mid-file would be misread. */
		$section = BetterPMMPConfigComments::expand(implode("\n", array_slice($lines, $start)), $lang);
		$body = rtrim($content, "\n");
		$section = rtrim($section, "\n");
		$result = "{$body}\n\n{$section}\n";
		return self::parse($result) === null ? $content : $result;
	}

	/**
	 * @return array<int|string, mixed>|null
	 */
	private static function parse(string $content) : ?array{
		try{
			$data = ErrorToExceptionHandler::trap(static fn() : mixed => yaml_parse($content));
		}catch(\ErrorException){
			return null;
		}
		return is_array($data) ? $data : null;
	}

	/**
	 * @param array<int|string, mixed> $data
	 */
	private static function enforceEnabled(array $data) : bool{
		$node = $data;
		foreach(explode('.', BetterPMMPProperties::CONFIG_ENFORCE_FORMAT) as $part){
			if(!is_array($node) || !array_key_exists($part, $node)){
				return false;
			}
			$node = $node[$part];
		}
		return $node === true;
	}

	/**
	 * @param array<int|string, mixed> $data
	 */
	private static function rebuild(string $template, array $data) : string{
		$out = [];
		/** @phpstan-var list<array{int, string, bool}> $stack */
		$stack = [];
		/** @phpstan-var array<string, array<string, true>> $seen */
		$seen = [];
		foreach(explode("\n", str_replace("\r\n", "\n", $template)) as $line){
			if(preg_match(self::KEY_LINE_PATTERN, $line, $m) !== 1){
				$out[] = $line;
				continue;
			}
			$indent = strlen($m[1]);
			while($stack !== [] && $stack[count($stack) - 1][0] >= $indent){
				self::close($out, $stack, $seen, $data);
			}
			$key = $m[2];
			$seen[implode("\0", array_column($stack, 1))][$key] = true;
			$path = array_column($stack, 1);
			$path[] = $key;
			[$valuePart, $comment] = self::splitValueComment($m[3]);
			if($valuePart === ''){
				$stack[] = [$indent, $key, true];
				$out[] = $line;
				continue;
			}
			$stack[] = [$indent, $key, false];
			$found = false;
			$value = self::lookup($data, $path, $found);
			if(!$found){
				$out[] = $line;
			}elseif(is_array($value)){
				if($value === []){
					$empty = $valuePart === '[]' ? '[]' : '{}';
					$out[] = "{$m[1]}{$key}: {$empty}{$comment}";
				}else{
					$out[] = "{$m[1]}{$key}:";
					foreach(self::emitBlock($value, "{$m[1]}  ") as $blockLine){
						$out[] = $blockLine;
					}
				}
			}else{
				$scalar = self::scalar($value);
				$out[] = "{$m[1]}{$key}: {$scalar}{$comment}";
			}
		}
		while($stack !== []){
			self::close($out, $stack, $seen, $data);
		}
		self::insertExtras($out, '', self::extras($data, $seen[''] ?? []));
		return implode("\n", $out);
	}

	/**
	 * @param list<string>                     $out
	 * @param list<array{int, string, bool}>   $stack
	 * @param array<string, array<string, true>> $seen
	 * @param array<int|string, mixed>         $data
	 */
	private static function close(array &$out, array &$stack, array $seen, array $data) : void{
		$entry = array_pop($stack);
		if($entry === null || !$entry[2]){
			return;
		}
		$path = array_column($stack, 1);
		$path[] = $entry[1];
		$found = false;
		$value = self::lookup($data, $path, $found);
		if(!$found || !is_array($value) || $value === []){
			return;
		}
		$children = $seen[implode("\0", $path)] ?? [];
		$indent = str_repeat(' ', $entry[0] + 2);
		if(array_is_list($value)){
			if($children === []){
				self::insertExtras($out, $indent, $value);
			}
			return;
		}
		self::insertExtras($out, $indent, self::extras($value, $children));
	}

	/**
	 * @param array<int|string, mixed> $value
	 * @param array<string, true>      $children
	 * @return array<int|string, mixed>
	 */
	private static function extras(array $value, array $children) : array{
		$extras = [];
		foreach($value as $k => $v){
			if(!isset($children[(string) $k])){
				$extras[$k] = $v;
			}
		}
		return $extras;
	}

	/**
	 * @param list<string>             $out
	 * @param array<int|string, mixed> $extras
	 */
	private static function insertExtras(array &$out, string $indent, array $extras) : void{
		if($extras === []){
			return;
		}
		$tail = [];
		while($out !== [] && trim($out[count($out) - 1]) === ''){
			$tail[] = array_pop($out);
		}
		foreach(self::emitBlock($extras, $indent) as $line){
			$out[] = $line;
		}
		foreach(array_reverse($tail) as $line){
			$out[] = $line;
		}
	}

	/**
	 * @param array<int|string, mixed> $value
	 * @return list<string>
	 */
	private static function emitBlock(array $value, string $indent) : array{
		$lines = [];
		if(array_is_list($value)){
			foreach($value as $item){
				if(!is_array($item)){
					$scalar = self::scalar($item);
					$lines[] = "{$indent}- {$scalar}";
					continue;
				}
				if($item === []){
					$lines[] = "{$indent}- {}";
					continue;
				}
				$lines[] = "{$indent}-";
				foreach(self::emitBlock($item, "{$indent}  ") as $nested){
					$lines[] = $nested;
				}
			}
			return $lines;
		}
		foreach($value as $k => $v){
			$key = is_int($k) ? (string) $k : self::scalar($k);
			if(!is_array($v)){
				$scalar = self::scalar($v);
				$lines[] = "{$indent}{$key}: {$scalar}";
				continue;
			}
			if($v === []){
				$lines[] = "{$indent}{$key}: {}";
				continue;
			}
			$lines[] = "{$indent}{$key}:";
			foreach(self::emitBlock($v, "{$indent}  ") as $nested){
				$lines[] = $nested;
			}
		}
		return $lines;
	}

	/**
	 * @param array<int|string, mixed> $data
	 * @param list<string>             $path
	 */
	private static function lookup(array $data, array $path, bool &$found) : mixed{
		$node = $data;
		foreach($path as $part){
			if(!is_array($node) || !array_key_exists($part, $node)){
				$found = false;
				return null;
			}
			$node = $node[$part];
		}
		$found = true;
		return $node;
	}

	/**
	 * @return array{string, string}
	 */
	private static function splitValueComment(string $rest) : array{
		if($rest === '' || $rest[0] === '#'){
			return ['', ''];
		}
		$offset = 0;
		$first = $rest[0];
		if($first === '"' || $first === "'"){
			$end = strpos($rest, $first, 1);
			if($end !== false){
				$offset = $end;
			}
		}
		$pos = strpos($rest, ' #', $offset);
		if($pos === false){
			return [rtrim($rest), ''];
		}
		return [rtrim(substr($rest, 0, $pos)), substr($rest, $pos)];
	}

	private static function scalar(mixed $value) : string{
		if(is_bool($value)){
			return $value ? 'true' : 'false';
		}
		if(is_int($value)){
			return (string) $value;
		}
		if(is_float($value)){
			return var_export($value, true);
		}
		if($value === null){
			return 'null';
		}
		if(is_string($value) && !str_contains($value, "\n")){
			$lines = explode("\n", yaml_emit($value, YAML_UTF8_ENCODING));
			if(count($lines) === 3 && $lines[1] === '...' && strpos($lines[0], '--- ') === 0){
				return substr($lines[0], 4);
			}
		}
		return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
