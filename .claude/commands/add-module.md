BetterPMMP 강화 모듈 "$ARGUMENTS" 를 추가하세요. 모듈은 `better-pmmp.*` 설정 키로 켜고 끄는 강화 항목이며, **7지점을 모두 채워야 완성**입니다. 하나라도 빠지면 유령 키·기본값 불일치·특정 언어 주석 누락으로 조용히 깨집니다.

## 입력 해석

`$ARGUMENTS` 에서 아래 4가지를 확정. 하나라도 불명확하면 ≤200자 질문 1회 후 진행.

- **키 경로** — `better-pmmp.` 를 뺀 나머지 (예: `network.foo-bar`). 소문자 케밥, 섹션은 기존 것 우선(`config` `world` `lighting` `entities` `combat` `network` `events` `recipes` `plugins` `gameplay`).
- **타입** — bool / int / string / map
- **기본값** — **바닐라와 동일한 동작을 내는 값**. 새 모듈의 기본값은 원칙적으로 "꺼짐"이다. 켜는 것이 기본이 되려면 근거를 보고에 명시.
- **동작** — 무엇을 어디서 바꾸는가. 대상 파일·메서드를 `Read` 로 확인하고 시작한다.

시작 전에 키 중복을 확인: `grep -rn "<키 경로>" source/src/betterpmmp/BetterPMMPProperties.php source/resources/pocketmine.yml`

## 7지점 — 순서 고정

### 1. `source/src/betterpmmp/BetterPMMPProperties.php`
- 상수명 = 키 경로 대문자 스네이크(`.` `-` → `_`). 예: `network.foo-bar` → `NETWORK_FOO_BAR = 'better-pmmp.network.foo-bar'`
- 해당 섹션 그룹 블록 안, **yml 과 같은 순서 위치**에 넣는다. 새 섹션이면 빈 줄로 구분한 새 그룹.

### 2. `source/resources/pocketmine.yml`
- `better-pmmp:` 아래 해당 섹션에 두 줄:
  `#! pocketmine.betterpmmp.yml.<키 경로>` / `<마지막 키>: <기본값>`
- 들여쓰기 2칸 단위, 섹션 중첩은 키 경로와 1:1. 새 섹션이면 섹션 자체의 `#!` 주석 줄도 함께 만든다.
- **CRLF 유지** (§CLAUDE.md 파일 형식). 마커 표기는 `#! ` 한 가지뿐.

### 3. `source/resources/translations/*.ini` — 14개 전부
- 키: `pocketmine.betterpmmp.yml.<키 경로>=<설명>`. 기존 `pocketmine.betterpmmp.*` 블록 안, yml 과 같은 순서 위치.
- **각 언어로 번역해서 넣는다.** 기존 45개 키가 14개 언어 전부 번역돼 있으므로 영어 복붙은 회귀다. bul.ini 도 예외 없이 포함.
- 설명문은 "무엇을 하는가 + 기본값이 왜 그것인가 + 켰을 때의 대가"를 1–3문장. 줄바꿈은 `\n` 리터럴.
- CRLF·UTF-8·BOM 없음. 14개 파일을 손으로 고치지 말고 번들 php 스크립트로 일괄 삽입한 뒤,
  `for f in source/resources/translations/*.ini; do printf "%s %s\n" "$(basename $f)" "$(grep -c 'pocketmine.betterpmmp' $f)"; done`
  로 **전 파일 키 개수가 동일한지** 확인한다.

### 4. 호출부 — 실제 동작 코드
- 읽기: `Server::getInstance()->getConfigGroup()->getPropertyBool|Int|String(BetterPMMPProperties::X, <기본값>)`. map 타입은 `getProperty(..., [])`.
- **기본값 인자는 2번 yml 기본값과 문자 그대로 같아야 한다.**
- 값은 시작 시 1회만 읽는 것이 이 프로젝트의 계약(README 명시). 핫패스면 `??=` 로 캐시한다 — 엔티티·세션별 상태면 인스턴스 필드, 전역이면 `private static ?T $x = null`.
- **꺼진 경로는 바닐라와 완전히 동일해야 한다.** 분기는 가능한 한 바깥에서 한 번만, 꺼졌을 때 추가 연산·할당·이벤트 호출 0. 이 조건을 만족 못 하는 설계면 구현하지 말고 재설계.
- 변경·추가 지점마다 `/** [BetterPMMP-PATCH] <영문 설명> */` (§CLAUDE.md 주석). 캐시 필드에도 `/** [BetterPMMP-PATCH] Cached <키> */`.
- 새 파일은 만들지 않는다. 로직이 바닐라 클래스와 독립적일 때만 `source/src/betterpmmp/` 에 LGPL 헤더를 붙여 생성.

### 5·6. `README.md` / `README.ko.md`
- 두 파일의 `| 키 | 기본값 | 설명 |` 표에 행 추가. 위치는 yml 순서와 동일, 키·기본값은 백틱.
- 설명은 3번 번역문과 같은 내용을 각 README 언어로. **표의 기본값이 코드·yml 과 어긋나면 그 자체가 결함이다.**

### 7. 검증
- phpstan — CLAUDE.md `## 검증` 절차 그대로, 수정한 파일 전부. **EXIT 0 확인 후에만 통과 단정.**
- 기본값 3중 일치(코드 인자 / yml / README 2곳)를 직접 대조해 확인.
- 기존 설정 파일에 키가 스플라이스되는지 확인 — 임시 데이터 디렉토리에 새 키가 없는 `pocketmine.yml` 을 두고 헤드리스 부팅한 뒤, 새 키가 주석과 함께 추가되었고 `#!` 잔여 마커가 없는지 본다.
  `./bin/php/php.exe src/PocketMine.php --no-wizard --disable-ansi --no-log-file "--data=<임시>" "--plugins=<임시>/plugins"` (옵션은 반드시 `--opt=value` 형식 — 공백 구분 시 뒤 플래그가 무시되고 마법사가 STDIN 에서 멈춘다.)

## CLAUDE.md 우선

- CLAUDE.md 규칙이 본 명령어보다 **항상 우선**. 특히 LGPL 헤더·마커, 주석 금지, 타입 힌트, `===`, 출력 함수 금지, 소스 문자열 영어 전용.
- `git add -A` 금지. 커밋·push 는 사용자 지시가 있을 때만.

## 종료 조건

7지점이 모두 채워지고 phpstan EXIT 0 인 상태. 하나라도 미완이면 완료 보고 금지.
보고는 1–3문장 — 어떤 키를 어떤 기본값으로 추가했고 어느 파일의 동작을 바꿨는지. 검증 절차·체크리스트 노출 금지.

## 거절 조건

- 키 경로가 이미 존재 → 기존 모듈 확장인지 ≤200자 질문.
- 켜고 끌 수 없는 변경(항상 적용되는 수정) → 모듈이 아니다. 설정 키 없이 `[BetterPMMP-PATCH]` 수정만 할지 질문.
