# BetterPMMP

Minecraft: Bedrock Edition 서버 소프트웨어 **PocketMine-MP**의 유지보수 후속작. 성능·게임플레이·편의 기능 개선이 소스에 직접 반영되어 있습니다.

> 🌏 [English README](README.md)

## 소개

[PocketMine-MP](https://github.com/pmmp/PocketMine-MP)는 2026-07-09 메인테이너에 의해 아카이브되었고, 업스트림 팀은 더 이상 업데이트를 제공하지 않습니다. BetterPMMP는 그 코드베이스를 이어받는 파생 프로젝트입니다. **PocketMine-MP 5.44.3 기반**이며 전체 서버 소스를 [source/](source/)에 포함하므로, 별도의 패치 단계 없이 클론해서 바로 실행합니다.

BetterPMMP는 Mojang 및 원 PocketMine Team과 무관합니다. 저작자 표시와 수정 내역은 [NOTICE](NOTICE)를 참고하세요.

## 기능

모든 개선은 기본값에서 바닐라와 동일하게 동작합니다. `pocketmine.yml`의 튜닝 옵션은 전부 opt-in입니다.

### 개발 편의

- **재시작 명령** — `start.cmd`의 재시작 루프와 연동되는 `/restart`. 프로세스를 깨끗하게 새로 띄우므로 수정한 소스가 그대로 반영됩니다.
- **소스에서 바로 실행** — `start.cmd`가 `.phar` 대신 `source/src/PocketMine.php`를 직접 실행하므로, 수정한 소스가 다음 실행에 바로 반영됩니다.
- **로그·경로 정리** — 시작 로그를 정돈하고 data / log / crashdump 경로를 통합합니다.

### 성능

- **블록 입력 렉 수정** — 상호작용 전 주변 블록을 캡처해 두고 실제로 바뀐 블록만 되돌려 보내, 설치/파괴 시 고무줄 현상을 없앱니다.
- **고정 광원** — `LightPopulationTask`를 건너뛰고 광원 배열을 고정값으로 채워 비동기·직렬화·플러드필 비용을 없앱니다. `pocketmine.yml`에서 켤 수 있습니다.
- **월드별 시야 거리** — 월드마다 `view-distance`를 따로 지정(로비에 유용).
- **월드별 청크 틱** — `tick-radius`와 `blocks-per-subchunk-per-tick`를 월드별로 설정. 둘 다 `0`이면 랜덤 틱을 완전히 끕니다.
- **이벤트·네트워크 튜닝** — 이벤트 버스 패스트패스, 변경분만 보내는 속성 동기화, 더 싼 패킷 프레이밍, 블록·이웃 업데이트 캐싱 등 여러 엔진 수정.
- **PvP 토글** — 아레나 서버에 거의 필요 없는 바닐라 시스템을 끄는 opt-in 스위치: 런타임 광원 갱신, 경험치 오브, 폭발 블록 파괴, 아이템 병합, 빈 월드 틱.

### 게임플레이

- **크리티컬 히트** — `pocketmine.yml`로 설정. 기본값은 바닐라와 동일합니다.
- **철문 상호작용 방지** — 손으로 철문을 여닫지 못하게 합니다.

## 요구 사항

- Windows (시작 스크립트가 `start.cmd` 기준)
- PHP 8 바이너리. `source/bin/php/php.exe`에 하나가 포함되어 있으며, 없을 경우 [pmmp/PHP-Binaries](https://github.com/pmmp/PHP-Binaries/releases)의 PM5 빌드를 받거나 `PATH`에 등록된 `php.exe`를 사용합니다.

## 설치

1. 이 저장소를 클론하거나 내려받습니다.
2. `start.cmd`로 서버를 시작합니다.

끝입니다 — `source/` 안의 소스에 모든 BetterPMMP 변경이 이미 반영되어 있습니다.

## 라이선스

BetterPMMP는 PocketMine-MP와 동일하게 **GNU Lesser General Public License v3.0 or later** (LGPL-3.0-or-later)로 배포됩니다. 전문은 [LICENSE](LICENSE), 저작자 표시는 [NOTICE](NOTICE)를 참고하세요.
