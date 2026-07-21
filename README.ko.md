# BetterPMMP
마인크래프트 **베드락 에디션** 서버를 여는 프로그램인 **PocketMine-MP**의 유지보수 후속작입니다. 성능, 게임 플레이, 관리 편의를 높이는 개선을 소스에 직접 반영했습니다.

> 🌏 [English README](README.md)

## 소개

마인크래프트 베드락 서버를 열려면 서버 프로그램이 필요하며, 대표적인 것이 [PocketMine-MP](https://github.com/pmmp/PocketMine-MP)입니다. 그러나 이 프로그램은 2026년 7월 9일 개발이 종료되어 더 이상 새 버전이 나오지 않습니다.

BetterPMMP는 그 프로그램을 이어받아 유지보수를 계속하는 후속 프로젝트입니다. PocketMine-MP 5.44.3 버전을 기반으로 하며, 서버 구동에 필요한 파일이 [source/](source/) 폴더에 모두 포함되어 있습니다. 따라서 별도의 설치 과정 없이 내려받아 바로 실행할 수 있습니다.

BetterPMMP는 마인크래프트 제작사 Mojang 및 원 PocketMine 팀과 무관합니다. 원저작자 표시와 변경 내역은 [NOTICE](NOTICE)를 참고하십시오.

## 주요 기능

성능 개선은 기본으로 켜져 있고, 게임 플레이를 바꾸지 않습니다. 게임 동작이 달라지는 기능(밝기 고정, 월드별 설정, PvP용 절약 옵션 등)은 기본으로 꺼져 있으니, 필요할 때 설정 파일(`pocketmine.yml`)에서 켜면 됩니다.

### 서버 관리

- **게임 내 재시작**: 채팅창에 `/restart`를 입력하면 서버를 껐다 켠 것처럼 깨끗하게 재시작합니다. `start.cmd`·`start.sh`의 재시작 반복문이 이를 처리합니다.
- **수정 내용 즉시 반영**: 서버 소스를 수정하면 다음 실행 시 적용됩니다. 별도의 빌드 과정이 없습니다.
- **정돈된 시작 화면**: 서버 시작 시 출력되는 불필요한 메시지를 정리하고, 기록/오류 파일의 저장 위치를 한곳으로 통합했습니다.
- **설정 주석 다국어 지원**: `pocketmine.yml`·`resource_packs.yml`의 설명 주석이 설치 시 고른 언어로 표시되고, 언어를 바꾸면 주석도 따라 번역됩니다.

### 성능

- **블록 동기화 절약**: 상호작용 직전의 클릭 대상 블록을 기억해 두었다가, 실제로 바뀌지 않았으면 다시 보내지 않습니다. 주변 블록은 클라이언트의 예측을 덮어쓰기 위해 그대로 전송합니다.
- **밝기 계산 생략**: 청크를 불러올 때 밝기를 고정값으로 채워 매번 다시 계산하지 않으므로 서버 부하가 크게 줄어듭니다. 밝기가 균일해지므로 필요할 때만 설정에서 켜십시오.
- **월드별 시야 거리**: 월드마다 보이는 거리를 다르게 지정할 수 있습니다.
- **월드별 자연 변화 조절**: 작물 성장, 잔디 번짐, 나뭇잎 마름처럼 저절로 일어나는 변화가 적용되는 범위를 월드별로 지정하거나 완전히 끌 수 있습니다.
- **내부 처리/통신 최적화**: 서버 내부 동작과 데이터 송수신 방식을 여러 부분 개선하여 전체 부하를 낮췄습니다.
- **반복 작업 낭비 제거**: 갑옷 착용 효과 검사, 패킷 처리 기록, 블록 무작위 성장 검사처럼 매 순간 반복되는 내부 작업에서 불필요한 복사와 계산을 없앴습니다. 게임 동작은 그대로이고 서버 부담만 줄어듭니다.
- **Snappy 압축 지원**: 데이터 압축을 zlib 대신 Snappy로 처리해 CPU 부담을 줄이는 선택 옵션입니다. snappy PHP 확장이 필요하며 동봉된 PHP에는 이미 포함되어 있고, 없으면 경고를 남기고 zlib을 그대로 사용합니다.
- **추가 절약 옵션**: PVP 서버에 거의 필요 없는 기능(실시간 밝기 갱신, 경험치 오브, 폭발에 의한 블록 파괴, 바닥 아이템 병합, 빈 월드 틱)을 꺼서 서버를 가볍게 만드는 선택 옵션입니다.

### 게임 플레이

- **크리티컬(치명타) 설정**: 치명타 동작을 설정에서 조정할 수 있습니다.
- **철문·철 다락문 손 조작 차단**: 손으로 철문과 철 다락문을 여닫을 수 없게 합니다. 레드스톤으로만 열리는 바닐라와 같은 동작이며, `gameplay.iron-door-hand-interaction`으로 이전 동작을 되돌릴 수 있습니다.
- **기본 규칙 끄기**: 허기 소모, 낙하 데미지, 경작지 마름·밟힘을 각각 끌 수 있습니다. 미니게임·아레나 서버용이며 기본값은 모두 바닐라와 동일합니다.
- **상위 프로젝트 전송 차단**: 크래시 리포트 전송, 업데이트 확인, 사용 통계가 모두 기본으로 꺼져 있습니다. 기본 서버 주소는 보관 처리된 PocketMine-MP의 것이며 이 포크와는 무관합니다.

## 설정

모든 옵션은 `pocketmine.yml`의 `better-pmmp:` 항목 아래에 있고, 각 옵션마다 서버 언어로 된 설명 주석이 붙어 있습니다. 새 버전에서 추가된 옵션은 다음 실행 시 기존 설정 파일에 주석과 함께 자동으로 채워집니다. **모든 옵션은 서버를 재시작해야 반영됩니다** — 값은 시작 시 한 번만 읽습니다.

이 항목 밖에서도 두 가지가 상위 버전과 다릅니다. `network.compression-level`이 6 대신 1이고(압축 비용이 훨씬 낮은 대신 전송량이 10~15% 늘어나며, 틱 시간에 유리한 선택입니다), `auto-report`·`auto-updater`·`anonymous-statistics`가 모두 기본으로 꺼져 있습니다.

| 키 | 기본값 | 설명 |
| --- | --- | --- |
| `config.enforce-format` | `false` | 시작할 때마다 이 파일을 BetterPMMP 형식으로 다시 작성합니다. 값은 유지되지만 직접 단 주석은 사라집니다. |
| `world.block-cache-size` | `2048` | 월드별 블록·충돌 캐시 크기. |
| `world.neighbour-update-limit` | `0` | 틱당 최대 인접 블록 업데이트 수. `0`이 바닐라입니다. |
| `world.freeze-empty-worlds` | `false` | 플레이어 없는 월드를 100틱당 1틱만 실행합니다. |
| `world.view-distance-per-world` | `{}` | 월드 폴더 이름별 시야 거리. |
| `world.chunk-ticking.batch-recheck-limit` | `64` | 틱당 티킹 대상 재확인 최대 청크 수. |
| `world.chunk-ticking.per-world` | `{}` | 월드별 `tick-radius` / `blocks-per-subchunk-per-tick`. 둘 다 `0`이면 자연 변화가 멈춥니다. |
| `lighting.fixed-light` | `false` | 밝기 계산을 생략하고 고정값으로 채웁니다. `skip-runtime-updates`가 함께 적용됩니다. |
| `lighting.fixed-light-level` | `15` | `fixed-light`가 사용할 밝기. |
| `lighting.skip-runtime-updates` | `false` | 블록 변경 시 밝기 재계산을 생략합니다. |
| `entities.item-merging` | `true` | 근처 바닥 아이템을 합칩니다. |
| `entities.item-despawn-ticks` | `6000` | 바닥 아이템 소멸 시간. `-1`은 소멸하지 않습니다. |
| `entities.xp-orbs` | `true` | 경험치 오브를 생성합니다. 끄면 경험치가 지급되지 않고 **소멸**합니다. |
| `entities.pickup-scan-period` | `1` | N틱마다 아이템 획득을 검사합니다. |
| `combat.critical-hit-ignore-sprint` | `false` | 달리는 중에도 치명타를 허용합니다. |
| `combat.critical-hit-min-fall-distance` | `0.0` | 치명타에 필요한 최소 낙하 거리. |
| `combat.explosion-block-destruction` | `true` | 폭발이 블록을 파괴합니다. 끄면 TNT 연쇄 폭발도 멈춥니다. |
| `combat.instant-hit-feedback` | `true` | 타격 반응을 틱 종료 대신 즉시 전송합니다. |
| `network.snappy-compression` | `false` | zlib 대신 Snappy를 사용합니다(`ext-snappy` 필요). |
| `network.movement-broadcast-period` | `1` | N틱마다 이동 패킷을 전송합니다. |
| `network.rotation-broadcast-period` | `1` | 제자리에서 시선만 돌릴 때 N틱마다 회전을 전송합니다. |
| `network.skip-movement-send-event` | `false` | 이동·모션 패킷의 `DataPacketSendEvent`를 생략합니다. |
| `network.skip-auth-input-receive-event` | `false` | 입력 패킷의 `DataPacketReceiveEvent`를 생략합니다. |
| `network.interaction-spam-window` | `20` | 중복 상호작용 무시 시간(ms). 원본은 100입니다. |
| `network.block-sync-snapshot` | `true` | 변경되지 않은 클릭 대상 블록의 재전송을 생략합니다. |
| `network.chunk-history-limit` | `8192` | 월드 전환 정리를 위해 세션당 기억할 청크 좌표 수. |
| `events.move-event-period` | `1` | N틱마다 `PlayerMoveEvent`를 발생시킵니다. 2 이상은 안티치트·지역 플러그인을 무력화합니다. |
| `recipes.load-vanilla` | `true` | 바닐라 조합·화로·양조 레시피를 등록합니다. |
| `plugins.lifecycle-log` | `true` | 플러그인 로드·활성화·비활성화를 기록합니다. |
| `gameplay.hunger-exhaustion` | `true` | 모든 원인으로 인한 허기 소모. |
| `gameplay.fall-damage` | `true` | 생명체의 낙하 데미지. |
| `gameplay.iron-door-hand-interaction` | `false` | 맨손으로 철문·철 다락문을 여닫습니다. 바닐라는 레드스톤이 필요합니다. |
| `gameplay.farmland-persistent` | `false` | 경작지가 마르거나 밟혀도 흙으로 되돌아가지 않습니다. |
| `gameplay.farmland-instant-hydration` | `false` | 일구거나 설치한 경작지가 젖은 상태로 시작합니다. 계속 젖어 있으려면 `farmland-persistent`가 필요합니다. |

게임 동작을 바꾸는 옵션은 기본으로 꺼져 있고, 바닐라와 다르게 동작할 수 있는 성능 옵션(`neighbour-update-limit`, `move-event-period`, `movement-broadcast-period`, `rotation-broadcast-period`, `pickup-scan-period`)은 바닐라와 같은 값으로 배포됩니다.

## 요구 사항

- **Windows**(`start.cmd`) 또는 **Linux**(`start.sh`)
- **PHP**: 서버 프로그램 구동에 필요한 실행 도구입니다. Windows용은 `source/bin/php/php.exe`에 포함되어 있어 별도 설치가 필요 없습니다. 파일이 없거나 Linux를 사용한다면 [pmmp/PHP-Binaries](https://github.com/pmmp/PHP-Binaries/releases)에서 PM5용 빌드를 받아 `source/bin/php/`에 넣거나, `php`를 `PATH`에 두면 됩니다. 시작 스크립트는 동봉된 실행 파일을 먼저 찾고 없으면 `PATH`를 사용합니다.

## 설치

1. 이 저장소를 내려받습니다(초록색 **Code** 버튼 → **Download ZIP**, 또는 `git clone`).
2. Windows에서는 `start.cmd`를, Linux에서는 `./start.sh`를 실행하여 서버를 시작합니다.

이게 전부입니다. 소개한 개선 사항이 모두 `source/`에 들어 있습니다.

## 라이선스

BetterPMMP는 PocketMine-MP와 동일하게 **GNU Lesser General Public License v3.0 or later** (LGPL-3.0-or-later)로 배포됩니다. 전문은 [LICENSE](LICENSE), 원저작자 표시는 [NOTICE](NOTICE)를 참고하십시오.
