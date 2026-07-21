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
 * @author BetterPMMP Team
 * @link https://github.com/jv2w/BetterPMMP
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\betterpmmp;

use PHPUnit\Framework\TestCase;
use pocketmine\lang\Language;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_merge;
use function basename;
use function explode;
use function file_get_contents;
use function glob;
use function implode;
use function is_array;
use function is_nan;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_contains;
use function str_starts_with;
use function yaml_parse;

/**
 * [BetterPMMP-PATCH]
 * Covers the config format engine, which rewrites the operator's live pocketmine.yml on every startup. Each
 * case here is a defect that shipped at some point: a value or a comment silently disappearing, a file that
 * never converged, or a setting that never reached an existing config.
 */
class BetterPMMPConfigFormatTest extends TestCase{

	private const RESOURCES = __DIR__ . '/../../../resources';

	private string $template;

	protected function setUp() : void{
		$this->template = file_get_contents(self::RESOURCES . '/pocketmine.yml');
	}

	private function lang(string $code) : Language{
		return new Language($code, self::RESOURCES . '/translations');
	}

	/**
	 * @return array<int|string, mixed>
	 */
	private function parse(string $yaml) : array{
		$data = yaml_parse($yaml);
		self::assertIsArray($data, 'output is not parseable YAML');
		return $data;
	}

	/**
	 * @param array<int|string, mixed> $data
	 * @return list<string>
	 */
	private function leafPaths(array $data, string $prefix = '') : array{
		$paths = [];
		foreach($data as $key => $value){
			$path = $prefix === '' ? (string) $key : "$prefix.$key";
			if(is_array($value) && $value !== [] && !array_is_list($value)){
				$paths = array_merge($paths, $this->leafPaths($value, $path));
			}else{
				$paths[] = $path;
			}
		}
		return $paths;
	}

	private function lookup(mixed $data, string $path) : mixed{
		foreach(explode('.', $path) as $part){
			if(!is_array($data) || !array_key_exists($part, $data)){
				return null;
			}
			$data = $data[$part];
		}
		return $data;
	}

	public function testRenderKeepsEveryTemplateValue() : void{
		$expected = $this->parse($this->template);
		foreach(['eng', 'kor'] as $code){
			$rendered = BetterPMMPConfigComments::render($this->template, $this->lang($code));
			self::assertSame($expected, $this->parse($rendered), "render($code) changed a value");
		}
	}

	public function testEveryLanguagePairConvertsExactly() : void{
		$codes = array_map(static fn(string $path) : string => basename($path, '.ini'), glob(self::RESOURCES . '/translations/*.ini'));
		$rendered = [];
		foreach($codes as $code){
			$rendered[$code] = BetterPMMPConfigComments::render($this->template, $this->lang($code));
		}
		foreach($codes as $from){
			foreach($codes as $to){
				if($from === $to){
					continue;
				}
				self::assertSame(
					$rendered[$to],
					BetterPMMPConfigComments::retranslate($rendered[$from], $this->template, $this->lang($to)),
					"$from -> $to did not produce the same file as rendering $to directly"
				);
			}
		}
	}

	public function testConfigCarriedOverFromUpstreamIsLocalized() : void{
		//upstream PocketMine-MP writes its comments as "#text", with no space after the hash
		$english = BetterPMMPConfigComments::render($this->template, $this->lang('eng'));
		$upstreamStyle = [];
		foreach(explode("\n", $english) as $line){
			if(str_starts_with($line, '#@ betterpmmp-lang:')){
				continue;
			}
			$upstreamStyle[] = preg_match('/^(\s*)# (.*)$/', $line, $m) === 1 ? $m[1] . '#' . $m[2] : $line;
		}

		$korean = BetterPMMPConfigFormat::apply(implode("\n", $upstreamStyle), $this->template, $this->lang('kor'));
		self::assertSame(
			preg_match_all('/[\x{AC00}-\x{D7A3}]/u', BetterPMMPConfigComments::render($this->template, $this->lang('kor'))),
			preg_match_all('/[\x{AC00}-\x{D7A3}]/u', $korean),
			'comments written in the upstream style were left untranslated'
		);
	}

	public function testStampIsNotTrustedOverTheFileContents() : void{
		$korean = BetterPMMPConfigComments::render($this->template, $this->lang('kor'));
		$forged = preg_replace('/\A#@ betterpmmp-lang: \S+/', '#@ betterpmmp-lang: eng', $korean, 1);

		$result = BetterPMMPConfigComments::retranslate($forged, $this->template, $this->lang('eng'));
		self::assertSame(0, preg_match_all('/[\x{AC00}-\x{D7A3}]/u', $result), 'a hand-edited stamp suppressed the conversion');
	}

	public function testMissingOptionsAreAddedToAnExistingSection() : void{
		$expected = $this->leafPaths($this->parse($this->template)['better-pmmp']);

		$result = BetterPMMPConfigFormat::apply(
			"better-pmmp:\n  config:\n    enforce-format: false\nsettings:\n  async-workers: 4\n",
			$this->template,
			$this->lang('eng')
		);
		$data = $this->parse($result);

		self::assertSame($expected, $this->leafPaths($data['better-pmmp']), 'options shipped later never reached an existing section');
		self::assertSame(4, $data['settings']['async-workers'], 'an unrelated value was lost');
		self::assertSame($result, BetterPMMPConfigFormat::apply($result, $this->template, $this->lang('eng')), 'filling in options is not idempotent');
	}

	public function testEnforceFormatKeepsValuesTheTemplateDeclaresAsContainers() : void{
		$result = BetterPMMPConfigFormat::apply(
			"better-pmmp:\n  config:\n    enforce-format: true\nmemory:\n  memory-dump: 5\nchunk-ticking:\n  disable-block-ticking: []\n",
			$this->template,
			$this->lang('eng')
		);
		$data = $this->parse($result);

		self::assertSame(5, $this->lookup($data, 'memory.memory-dump'), 'a scalar under a template container was replaced by the template default');
		self::assertSame([], $this->lookup($data, 'chunk-ticking.disable-block-ticking'), 'an empty collection degraded to null');
		self::assertSame($result, BetterPMMPConfigFormat::apply($result, $this->template, $this->lang('eng')), 'enforce-format is not idempotent');
	}

	public function testEnforceFormatKeepsUnknownSectionsAndScalarTypes() : void{
		$input = "better-pmmp:\n  config:\n    enforce-format: true\nprobe:\n  inf: .inf\n  nan: .nan\n  quoted: 'it''s # here'\n  zero: 0.0\nmy-plugin:\n  nested:\n    a: 1\n";
		$data = $this->parse(BetterPMMPConfigFormat::apply($input, $this->template, $this->lang('eng')));

		self::assertSame(INF, $this->lookup($data, 'probe.inf'), 'INF came back as something else');
		self::assertTrue(is_nan($this->lookup($data, 'probe.nan')), 'NAN came back as something else');
		self::assertSame("it's # here", $this->lookup($data, 'probe.quoted'), 'a quoted value containing " #" was split at the hash');
		self::assertSame(0.0, $this->lookup($data, 'probe.zero'), '0.0 lost its type');
		self::assertSame(1, $this->lookup($data, 'my-plugin.nested.a'), 'an unknown section was lost');
	}

	public function testUserCommentsSurvive() : void{
		$input = "settings:\n  #!todo\n  # an ordinary note\n  async-workers: 4\n";
		$result = BetterPMMPConfigFormat::apply($input, $this->template, $this->lang('eng'));

		self::assertStringContainsString('#!todo', $result, 'a comment shaped like a marker was deleted');
		self::assertStringContainsString('# an ordinary note', $result, 'an ordinary comment was deleted');
		self::assertSame(4, $this->parse($result)['settings']['async-workers']);
	}

	public function testABareMarkerDoesNotSwallowTheFollowingLine() : void{
		$result = BetterPMMPConfigFormat::apply("#!\naliases:\nsettings:\n  async-workers: 4\n", $this->template, $this->lang('eng'));

		self::assertTrue(str_contains($result, 'aliases'), 'the line after a bare "#!" was swallowed');
	}

	public function testAConfigThatDeclaresNothingIsRegenerated() : void{
		foreach(["# every key here is commented out\n#settings:\n#  motd: hi\n", "   \n\n"] as $input){
			$data = $this->parse(BetterPMMPConfigFormat::apply($input, $this->template, $this->lang('eng')));
			self::assertArrayHasKey('better-pmmp', $data, 'a config with no values was not regenerated');
		}
	}

	public function testAnUnparseableConfigIsLeftAlone() : void{
		$broken = "settings:\n  motd: \"unterminated\n   bad: [1,2\n";
		$result = BetterPMMPConfigFormat::apply($broken, $this->template, $this->lang('eng'));

		self::assertStringContainsString('unterminated', $result, 'a file we cannot parse must not be thrown away');
	}
}
