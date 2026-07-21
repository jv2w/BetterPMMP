# BetterPMMP
아카이브된 PocketMine-MP를 이어받는 유지보수 후속작. **PocketMine-MP 5.44.3** 기반이며 전체 서버 소스를 `source/`에 담아 **직접 수정**한다. 모든 소스는 PMMP 코딩 규약과 phpstan(max)을 그대로 통과해야 함.

## 라이선스 — 최우선, 위반 시 배포 불가
- **LGPL-3.0-or-later.** `source/` 전체가 LGPL. 루트 `LICENSE`(LGPL 전문)·`NOTICE`(저작자 표시·§5a 수정 고지·상표)를 유지한다. MIT 등 다른 라이선스로 되돌리지 말 것.
- **파일 헤더 필수** — `source/src` 등 모든 `.php`는 최상단 PMMP LGPL 헤더(ASCII 아트 + "GNU Lesser General Public License" 블록)를 **반드시 유지**한다. 삭제·훼손 금지. **신규 파일도 동일 헤더를 붙여** 생성한다(기존 파일에서 헤더 블록 복사).
- **수정 고지(§5a)** — BetterPMMP가 수정·추가한 지점은 `[BetterPMMP-PATCH]` 마커로 표시한다(표기·형식은 §코드 규칙 「주석」). "PMMP 5.44.3에서 수정됨"의 근거이므로 제거 금지.
- **서드파티** — `source/vendor/*`의 각 `LICENSE`, `source/bin/php/license.txt`를 **삭제·수정 금지**. vendor 라이브러리·번들 PHP 바이너리는 각자의 라이선스로 재배포된다.

## 강화 원칙
- **정확성** — 작업 전 대상 소스를 `Read`로 확인하고 추측 금지.
- **무결성** — 바닐라 관찰 동작 회귀 0. 더 가볍고 안전한 대안을 항상 먼저 탐색하고, 문제 소지가 있으면 재설계.
- **강화 근거** — 각 변경이 소스를 어떻게 강화하는지(제거되는 오버헤드·고쳐지는 버그·줄어드는 MSPT)를 명시. 근거 없는 변경 금지.
- **성능 > 가독성** — 핫패스에서는 가독성보다 MSPT/TPS를 우선.

## 검증 (phpstan) — 매 변경마다 필수
- **dev 의존성은 `source/`에 절대 설치하지 않는다.** `cd source && composer install` 금지 — phpstan/phpunit 등이 `source/vendor/`에 쏟아지고 커밋에 휩쓸린 사고 이력이 있다. `source/vendor/`는 런타임 의존성만 추적한다.
- dev 도구는 리포 루트 `.tools/`(gitignored)에만 둔다. 없으면 생성: `mkdir -p .tools && composer require --working-dir=.tools phpstan/phpstan:2.1.46` (버전은 `source/composer.json`의 `require-dev`와 일치시킨다).
- 실행 — `source/`에서:
  `./bin/php/php.exe ../.tools/vendor/phpstan/phpstan/phpstan.phar analyse -l max --autoload-file=vendor/autoload.php --no-progress <변경 파일>`
- 번들 php(`source/bin/php/php.exe`)를 쓴다 — pmmp 확장이 리플렉션으로 잡혀 stub 누락 오탐이 사라진다. 임시 런타임 확인(`parse_ini_file`·`yaml_parse` 등)도 이 php로 한다.
- 리포에는 `phpstan.neon.dist`가 **없다**. 전체 트리 분석은 기존 노이즈가 섞이므로 **변경 파일로 범위를 좁히고**, 출력에서 자신이 건드린 파일·식별자만 판정한다.
- level `max`, PMMP 기준 완벽 준수. 타입·제네릭 PHPDoc·`@phpstan-*` 정확히. 새/수정 코드는 신규 에러 0. **EXIT 0 확인 후에만 "통과" 단정.**

## 코드 규칙
- **주석** — 설명용 주석 금지. 허용은 아래 3종뿐.
  1. 파일 최상단 LGPL 헤더 (§라이선스, 필수)
  2. 수정 마커 — 변경 지점마다 `/** [BetterPMMP-PATCH] <영문 설명> */`. 표기는 `[BetterPMMP-PATCH]` 한 가지만 쓰고 변형 금지. 긴 설명은 `/** [BetterPMMP-PATCH] …\n * … */` 로 잇는다. **이 마커 본문만이 "왜 바꿨는지"를 서술하는 예외**다(LGPL §5a 근거).
  3. 도구 지시자 `@var`·`@phpstan-ignore` 등
- **파일 형식** — `source/resources/**`(`*.ini`·`*.yml`)는 **CRLF** UTF-8(BOM 없음). 바이트 단위로 편집할 때 `\r\n`을 유지한다(섞이면 설정 마커·파서 회귀). Git-Bash `grep`은 `\r`를 감추므로 확인은 `grep -U`.
- **성능** — 반복 프로퍼티 접근 로컬 캐싱 / `switch`→`match` / 불변 `readonly` / `foreach` 우선 / 객체 복제·동적 프로퍼티·배열 재인덱싱 금지.
- **간결성** — 1–2줄 로직은 인라인. 함수화는 사용처 2개 이상 실존 시만. "재사용 가능성" 불인정. 콜백·클로저 직접 전달.
- **가드 클로즈** — 탈출은 단일 라인 가드. `return` 후 `else` 금지.
- **상태 설계** — 잘못된 상태는 타입·enum으로 설계 단계 제거. 에러 핸들링 필요 → 재설계.
- **트랜잭션** — 아이템 지급은 원자적·멱등적. 사전조건·중복 제거 필수.
- **네이밍** — 변수/함수 camelCase, 클래스 PascalCase. 축약어(변수·파라미터만): `message→msg` `player→pl` `configuration→config` `initialize→start` `parameter→input` `validate→check` `information→info` `temporary→temp`
- **문자열** — 작은따옴표 기본. 보간만 `"Hello {$name}"`. 연결(`'a' . $x`) 금지.
- **중괄호** — 클래스·함수 다음 줄. body 1문 한 줄, 2문↑ 블록.

## CRITICAL — 코드, 위반 시 즉시 실패
- 파일 첫 줄 `<?php`, 다음에 LGPL 헤더 블록, 그 뒤 `declare(strict_types=1);` — PMMP 표준 형식 유지.
- LGPL 헤더 삭제·훼손 (§라이선스)
- 허용 3종 외 주석 (§코드 규칙 「주석」)
- 타입 힌트 누락 (파라미터·반환·프로퍼티)
- `==` (반드시 `===`)
- 출력 함수: `echo` `print` `PHP_EOL` `var_dump` `print_r` `var_export` `error_log` `debug_print_backtrace` — 콘솔은 `$this->getLogger()` 전용
- 영어 전용 — 모든 소스 문자열 리터럴(수정·신규 코드·`start.cmd`/배치·로그·사용자 메시지 포함)은 영어만. 한국어 등 비영어 리터럴 금지

## CRITICAL — 작업, 위반 시 즉시 실패
- phpstan 미실행 상태에서 "오류 없음" 단정 (§검증)
- 사용자의 언급 없이 `push`
- `git add -A` / `git add -a` — 스테이징은 항상 명시 경로로. (`.tools/`·스크래치 파일이 휩쓸린 사고 이력)
- 검증 절차·체크리스트 사용자 노출 (내부 전용)
- 모호함 회피 — 클래스·기능명 명시 요청은 즉시 작업, 스펙 미정일 때만 ≤200자 질문
