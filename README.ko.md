# BetterPMMP

**PocketMine-MP 5.0.0** 소스 코드에 성능 최적화, 게임플레이 수정, 개발 편의 기능을 직접 적용하는 패치 도구입니다.

> 🌏 [English README](README.md)

## 동작 방식

`patch_tool.php`는 PMMP 소스 트리에 약 60개의 멱등(idempotent) 패치 함수를 실행합니다. 각 패치는:

- `[BetterPMMP-PATCH]` 마커를 남겨 중복 적용을 방지합니다.
- 종료 시 `APPLIED` / `SKIPPED` / `FAILED` 요약을 출력합니다.
- 이미 패치되었거나 일부만 패치된 트리에 다시 실행해도 안전합니다.

```bash
php patch_tool.php <소스_디렉터리_경로>
```

## 기능

### 개발 편의

- **플러그인 핫 리로드** - `/reload <플러그인명>` 명령으로 서버 재시작 없이 런타임에 플러그인을 리로드합니다. 이벤트 리스너, 명령어, 권한까지 완전히 언로드/리로드됩니다. 버전드 네임스페이스 기반 클래스 캐시 무효화(`ClassCacheInvalidator`)와 리소스 인덱스(`PluginResourceIndex` / `PluginResources`)로 역의존성 맵을 포함한 플러그인 상태를 추적·재구성합니다.
- **재시작 명령** - `/restart` 명령과 `start.cmd` 재시작 루프로 깔끔한 서버 순환을 지원합니다.
- **소스 기반 구동** - `.phar` 대신 `source/src/PocketMine.php`에서 실행하도록 `start.cmd`를 패치하여 소스 수정이 즉시 반영됩니다.
- **로그·경로 정리** - 시작 로그, info 프리픽스, GC 로그를 정돈하고 data / log / crashdump 경로를 통합합니다.

### 성능 최적화

- **블록 입력 렉 수정** - 스냅샷 기반 블록 동기화. 상호작용 전 주변 블록 상태를 캡처하고 실제로 변경된 블록만 클라이언트로 전송하여, 블록 설치/파괴 시 고무줄 현상(rubber-banding)을 제거합니다.
- **고정 광원(Fixed Light)** - 비동기 `LightPopulationTask`를 건너뛰고 광원 배열을 고정값으로 채워 비동기 작업 제출, igbinary 직렬화, BFS 플러드필 부하를 제거합니다. `pocketmine.yml`에서 설정 가능.
- **월드별 시야 거리** - 월드 단위로 `view-distance`를 재정의(로비 월드에 유용).
- **월드별 청크 틱** - 월드별로 `tick-radius`와 `blocks-per-subchunk-per-tick`를 독립 설정. 둘 다 `0`으로 두면 로비의 랜덤 틱을 완전히 비활성화합니다.
- **FPS / 네트워크 최적화** - 엔티티 브로드캐스트 배칭, 액터 애니메이션 거리 필터링, 파티클/사운드 거리 필터링, 청크 전송 페이싱, 아이템 엔티티 억제.
- **엔티티·월드 튜닝** - 제자리 이동 빠른 경로, 주변 블록 캐싱, 모션 엡실론 정리, 이웃 업데이트 스로틀링, 블록 캐시 크기 조정, 안전한 엔티티 틱/언로드 순회.
- **기타 엔진 튜닝** - 핸들러 리스트 병합/등록 캐싱, 네트워크 세션 핸들러 가드, 체력 부동소수 비교 수정, 리스폰 락 리셋, 제거 시 온라인 플레이어 스냅샷, class-map-authoritative 오토로딩.

### 게임플레이

- **크리티컬 히트** - `pocketmine.yml` / `server.properties`로 설정 가능한 크리티컬 히트 메커니즘.
- **철문 상호작용 방지** - 손 상호작용으로 철문이 토글되지 않도록 합니다.

## 요구 사항

- PocketMine-MP 5.0.0 소스 코드
- PHP 8.x — [pmmp/PHP-Binaries 릴리스](https://github.com/pmmp/PHP-Binaries/releases)의 PMMP 전용 PHP 바이너리
- Windows (`start.cmd` 기반 시작 스크립트)

## 설치

1. PocketMine-MP 5.0.0 소스를 `source/` 폴더에 둡니다.
2. PHP 바이너리를 준비합니다. `start.cmd`는 다음 두 위치 중 한 곳에서 PHP를 찾습니다:
   - **서버 내부** — `bin/php/php.exe` (로컬 바이너리가 있으면 항상 우선 사용), 또는
   - **시스템 경로** — `source/` 폴더 밖, `PATH`에 등록된 `php.exe`.

   [pmmp/PHP-Binaries 릴리스](https://github.com/pmmp/PHP-Binaries/releases)에서 환경에 맞는 빌드를 받아, 서버 내부 방식이라면 `bin/php/`에 압축을 풉니다.
3. 패치 도구를 실행합니다:
   ```bash
   php patch_tool.php source
   ```
   (또는 `makeBetterPMMP.bat` 실행)
4. `start.cmd`로 서버를 시작합니다.

패치는 멱등하므로 소스 업데이트 후 언제든 다시 실행할 수 있습니다.

## 라이선스

MIT
