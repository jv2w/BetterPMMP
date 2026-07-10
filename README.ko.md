# BetterPMMP

**PocketMine-MP 5.0.0** 소스에 성능·게임플레이·편의 기능 수정을 직접 적용하는 패치 도구.

> 🌏 [English README](README.md)

## 사용법

```bash
php patch_tool.php <소스_디렉터리>
```

`patch_tool.php`는 PMMP 소스에 약 50개의 패치를 적용합니다. 각 패치는 수정한 부분에 `[BetterPMMP-PATCH]` 마커를 남기므로, 이미 적용된 패치는 건너뛰고 다시 실행해도 안전합니다. 끝나면 `APPLIED` / `SKIPPED` / `FAILED` 요약이 출력됩니다.

모든 패치는 기본값에서 바닐라와 동일하게 동작합니다. `pocketmine.yml`의 튜닝 옵션은 전부 opt-in입니다.

## 기능

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

- PocketMine-MP 5.0.0 소스
- [pmmp/PHP-Binaries](https://github.com/pmmp/PHP-Binaries/releases)의 PMMP용 PHP 8 바이너리
- Windows (시작 스크립트가 `start.cmd` 기준)

## 설치

1. PocketMine-MP 5.0.0 소스를 `source/`에 넣습니다.
2. PHP 바이너리를 준비합니다 — 서버 내부 `bin/php/php.exe`(우선) 또는 `PATH`에 등록된 `php.exe`.
3. `php patch_tool.php source`를 실행합니다(또는 `makeBetterPMMP.bat`).
4. `start.cmd`로 서버를 시작합니다.

패치는 멱등하므로 소스를 업데이트할 때마다 다시 실행하면 됩니다.

## 라이선스

MIT
